<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Services\Impact\ImpactAnalyzer;
use DNDark\LogicMap\Services\Impact\ImpactPolicy;
use DNDark\LogicMap\Services\Impact\ImpactRequest;
use DNDark\LogicMap\Services\Impact\SharedResourceImpactAnalyzer;
use DNDark\LogicMap\Services\Impact\TestScopeResolver;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class ImpactAnalyzerTest extends CommerceFixtureTestCase
{
    public function test_modified_deleted_renamed_and_added_symbols_have_distinct_upstream_semantics(): void
    {
        $graph = new KnowledgeGraph();
        $caller = NodeId::method('App\Caller', 'run');
        $callee = NodeId::method('App\Gateway', 'execute');
        $added = NodeId::method('App\Gateway', 'newMethod');
        $this->addNode($graph, $caller);
        $this->addNode($graph, $callee);
        $this->addNode($graph, $added);
        $this->addEdge($graph, $caller, $callee, EdgeType::Calls, 10);

        $modified = $this->analyze($graph, [$this->change(ChangeType::Modified, $callee)]);
        self::assertSame(ImpactLevel::Direct, $this->reason($modified->affectedSymbols, $caller, ImpactCategory::HardDependency)?->level);

        $addedReport = $this->analyze($graph, [$this->change(ChangeType::Added, $added, null)]);
        self::assertNull($this->affected($addedReport->affectedSymbols, $caller));

        $deleted = $this->change(ChangeType::Deleted, null, $callee, [
            'diagnostic_callers' => [$caller->value],
            'diagnostic_evidence_ids' => [str_repeat('d', 64)],
        ]);
        $deletedReport = $this->analyze($graph, [$deleted]);
        self::assertSame(ImpactLevel::Breaks, $this->reason($deletedReport->affectedSymbols, $caller, ImpactCategory::HardDependency)?->level);

        $renamed = $this->change(ChangeType::Renamed, $added, $callee, [
            'diagnostic_callers' => [$caller->value],
            'diagnostic_evidence_ids' => [str_repeat('e', 64)],
        ]);
        $renamedReport = $this->analyze($graph, [$renamed]);
        self::assertCount(1, $renamedReport->changedSymbols);
        self::assertSame(ChangeType::Renamed, $renamedReport->changedSymbols[0]->changeType);
        self::assertSame(ImpactLevel::Breaks, $this->reason($renamedReport->affectedSymbols, $caller, ImpactCategory::HardDependency)?->level);
    }

    public function test_interface_change_reaches_implementations_and_callers(): void
    {
        $graph = new KnowledgeGraph();
        $interface = NodeId::fromString('interface:App\Contracts\Gateway');
        $interfaceMethod = NodeId::method('App\Contracts\Gateway', 'send');
        $implementation = NodeId::fromString('class:App\HttpGateway');
        $implementationMethod = NodeId::method('App\HttpGateway', 'send');
        $caller = NodeId::method('App\OrderService', 'submit');

        foreach ([$interface, $interfaceMethod, $implementation, $implementationMethod, $caller] as $id) {
            $this->addNode($graph, $id, str_starts_with($id->value, 'interface:') ? NodeKind::InterfaceSymbol : null);
        }
        $this->addEdge($graph, $interface, $interfaceMethod, EdgeType::Defines, 2);
        $this->addEdge($graph, $implementation, $interface, EdgeType::Implements, 3);
        $this->addEdge($graph, $implementation, $implementationMethod, EdgeType::Defines, 4);
        $this->addEdge($graph, $caller, $interfaceMethod, EdgeType::Calls, 5);

        $report = $this->analyze($graph, [$this->change(ChangeType::Modified, $interfaceMethod)]);

        self::assertSame(ImpactLevel::Direct, $this->reason($report->affectedSymbols, $caller, ImpactCategory::HardDependency)?->level);
        self::assertSame(ImpactLevel::Direct, $this->reason($report->affectedSymbols, $implementation, ImpactCategory::HardDependency)?->level);
    }

    public function test_event_change_reaches_listener_workflows_and_preserves_multiple_reasons(): void
    {
        $graph = new KnowledgeGraph();
        $event = NodeId::fromString('class:App\Events\Changed');
        $listener = NodeId::fromString('class:App\Listeners\React');
        $process = NodeId::fromString('process:event-change');
        foreach ([$event, $listener, $process] as $id) {
            $this->addNode($graph, $id, $id === $process ? NodeKind::Process : null);
        }
        $this->addEdge($graph, $listener, $event, EdgeType::Calls, 10);
        $this->addEdge($graph, $listener, $event, EdgeType::ListensTo, 11);
        $this->addEdge($graph, $event, $process, EdgeType::StepInProcess, 12);
        $this->addEdge($graph, $listener, $process, EdgeType::StepInProcess, 13);

        $report = $this->analyze($graph, [$this->change(ChangeType::Modified, $event)]);
        $affected = $this->affected($report->affectedSymbols, $listener);
        self::assertNotNull($affected);
        self::assertContains(ImpactCategory::HardDependency, array_map(static fn ($reason) => $reason->category, $affected->reasons));
        self::assertContains(ImpactCategory::Async, array_map(static fn ($reason) => $reason->category, $affected->reasons));
        self::assertNotNull($this->reason($report->affectedSymbols, $process, ImpactCategory::Workflow));

        foreach ($affected->reasons as $reason) {
            self::assertNotEmpty($reason->nodeChain);
            self::assertSame(count($reason->nodeChain) - 1, count($reason->edgeChain));
            self::assertNotEmpty($reason->evidenceIds);
            self::assertNotSame('', $reason->sentence);
        }
    }

    public function test_shared_state_traverses_writer_to_readers_and_reader_to_writers(): void
    {
        [$graph, $diagnostics] = $this->buildSemanticGraph();
        $writer = NodeId::method('Fixtures\CommerceApp\Services\OrderService', 'cancel');
        $shipping = NodeId::method('Fixtures\CommerceApp\Services\ShippingService', 'canShip');
        $dashboard = NodeId::method('Fixtures\CommerceApp\Services\SalesDashboardService', 'cancelledOrderCount');

        $writerReport = $this->analyze($graph, [$this->change(ChangeType::Modified, $writer)], $diagnostics);
        self::assertSame(ImpactLevel::SharedResource, $this->reason($writerReport->affectedSymbols, $shipping, ImpactCategory::SharedState)?->level);
        self::assertSame(ImpactLevel::SharedResource, $this->reason($writerReport->affectedSymbols, $dashboard, ImpactCategory::SharedState)?->level);

        $readerReport = $this->analyze($graph, [$this->change(ChangeType::Modified, $shipping)], $diagnostics);
        self::assertSame(ImpactLevel::SharedResource, $this->reason($readerReport->affectedSymbols, $writer, ImpactCategory::SharedState)?->level);
    }

    public function test_external_contract_and_adjacent_diagnostic_are_explained_independently(): void
    {
        [$graph, $diagnostics] = $this->buildSemanticGraph();
        $endpoint = NodeId::fromString('external:{config:services.erp.base_url}/orders/{id}/cancel');
        $caller = NodeId::method('Fixtures\CommerceApp\Listeners\SendCancellationWebhook', 'handle');
        $diagnostics[] = new Diagnostic(
            DiagnosticCode::UnresolvedReceiver,
            'resolve',
            'app/Listeners/SendCancellationWebhook.php',
            20,
            20,
            'Dynamic receiver could not be resolved.',
            ['enclosing_symbol_id' => $caller->value, 'call_site_evidence_id' => str_repeat('f', 64)],
        );

        $external = $this->analyze($graph, [$this->change(ChangeType::Modified, $endpoint)], $diagnostics);
        self::assertSame(ImpactLevel::Direct, $this->reason($external->affectedSymbols, $caller, ImpactCategory::ExternalContract)?->level);

        $uncertain = $this->analyze($graph, [$this->change(ChangeType::Modified, $caller)], $diagnostics);
        self::assertSame(ImpactLevel::Possible, $this->reason($uncertain->affectedSymbols, $caller, ImpactCategory::Uncertainty)?->level);
    }

    public function test_category_truncation_is_independent(): void
    {
        $graph = new KnowledgeGraph();
        $changed = NodeId::method('App\Service', 'changed');
        $callerOne = NodeId::method('App\Caller', 'one');
        $callerTwo = NodeId::method('App\Caller', 'two');
        $module = NodeId::fromString('module:Core');
        foreach ([$changed, $callerOne, $callerTwo, $module] as $id) {
            $this->addNode($graph, $id, $id === $module ? NodeKind::Module : null);
        }
        $this->addEdge($graph, $callerOne, $changed, EdgeType::Calls, 10);
        $this->addEdge($graph, $callerTwo, $changed, EdgeType::Calls, 11);
        $this->addEdge($graph, $changed, $module, EdgeType::MemberOfModule, 12);

        $report = $this->analyze($graph, [$this->change(ChangeType::Modified, $changed)], [], maxNodes: 1);

        self::assertTrue($report->truncation[ImpactCategory::HardDependency->value]['truncated']);
        self::assertFalse($report->truncation[ImpactCategory::Module->value]['truncated']);
        self::assertNotNull($this->affected($report->affectedSymbols, $module));
    }

    private function analyze(
        KnowledgeGraph $graph,
        array $changes,
        array $diagnostics = [],
        int $maxNodes = 100,
    ) {
        $directions = new EdgeDirectionPolicy();
        $policy = new ImpactPolicy($directions);
        $analyzer = new ImpactAnalyzer(
            $graph,
            $diagnostics,
            $policy,
            new SharedResourceImpactAnalyzer($graph, $policy),
            new TestScopeResolver($graph),
        );

        return $analyzer->analyze(new ImpactRequest(
            changedSymbols: $changes,
            maxNodes: $maxNodes,
            maxEdges: 200,
            maxDepth: 12,
            maxResponseBytes: 2_000_000,
        ));
    }

    private function change(
        ChangeType $type,
        ?NodeId $new,
        ?NodeId $old = null,
        array $attributes = [],
    ): ChangedSymbol {
        $new ??= $type === ChangeType::Deleted ? null : $old;
        $old ??= $type === ChangeType::Added ? null : $new;

        return new ChangedSymbol(
            $type,
            $old,
            $new,
            $old === null ? null : 'app/Old.php',
            $new === null ? null : 'app/New.php',
            $old === null ? null : 1,
            $old === null ? null : 1,
            $new === null ? null : 1,
            $new === null ? null : 1,
            new EvidenceRecord(
                EvidenceOrigin::GitDiff,
                'test-git-diff',
                Certainty::Certain,
                new SourceLocation($new === null ? 'app/Old.php' : 'app/New.php', 1, 1),
                null,
                null,
                ['change_type' => $type->value],
            ),
            $attributes,
        );
    }

    private function addNode(KnowledgeGraph $graph, NodeId $id, ?NodeKind $kind = null): void
    {
        $kind ??= str_starts_with($id->value, 'method:') ? NodeKind::Method : NodeKind::ClassSymbol;
        $graph->addNode(new GraphNode($id, $kind, $id->value, null, null));
    }

    private function addEdge(KnowledgeGraph $graph, NodeId $source, NodeId $target, EdgeType $type, int $line): void
    {
        $graph->addEdge(GraphEdge::fromEvidence(
            $source,
            $target,
            $type,
            new EvidenceRecord(
                EvidenceOrigin::StaticAst,
                'test-edge',
                Certainty::Certain,
                new SourceLocation('app/Test.php', $line, $line),
                $type->value,
            ),
        ));
    }

    private function affected(array $affected, NodeId $id): ?AffectedSymbol
    {
        foreach ($affected as $symbol) {
            if ($symbol->nodeId->equals($id)) {
                return $symbol;
            }
        }

        return null;
    }

    private function reason(array $affected, NodeId $id, ImpactCategory $category)
    {
        foreach ($this->affected($affected, $id)?->reasons ?? [] as $reason) {
            if ($reason->category === $category) {
                return $reason;
            }
        }

        return null;
    }
}
