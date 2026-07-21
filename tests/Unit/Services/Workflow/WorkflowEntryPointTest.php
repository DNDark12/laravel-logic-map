<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Workflow;

use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\Phases\BuildProcessMembershipPhase;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Services\Workflow\WorkflowBuilder;
use DNDark\LogicMap\Services\Workflow\WorkflowRequest;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class WorkflowEntryPointTest extends CommerceFixtureTestCase
{
    public function test_method_command_schedule_job_and_event_entrypoints_are_bounded_and_real(): void
    {
        [$graph, $diagnostics, , , , $outputs] = $this->buildSemanticGraph();
        $builder = new WorkflowBuilder($graph, $outputs, $diagnostics);
        $cases = [
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            'command:inventory:reconcile',
            'class:Fixtures\CommerceApp\Jobs\ReconcileInventoryJob',
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
        ];

        foreach ($cases as $entry) {
            $workflow = $builder->build(new WorkflowRequest(NodeId::fromString($entry), 80, 10));
            self::assertSame($entry, $workflow->entrypoint->value);
            self::assertNotEmpty($workflow->steps);
        }

        $schedule = array_values(array_filter(
            $graph->nodes(),
            static fn ($node): bool => $node->kind === NodeKind::Schedule,
        ));
        self::assertCount(1, $schedule);
        $scheduled = $builder->build(new WorkflowRequest($schedule[0]->id, 80, 10));
        self::assertNotEmpty(array_filter(
            $scheduled->transitions,
            static fn ($transition): bool => $transition->boundary === ExecutionBoundary::Scheduled,
        ));

        $event = $builder->build(new WorkflowRequest(
            NodeId::fromString('class:Fixtures\CommerceApp\Events\OrderCancelled'),
            80,
            10,
        ));
        $listenerIds = array_values(array_filter(array_map(
            static fn ($step): ?string => $step->nodeId?->value,
            $event->steps,
        ), static fn (?string $id): bool => is_string($id) && str_contains($id, 'Listeners')));
        self::assertContains('class:Fixtures\CommerceApp\Listeners\RestockInventory', $listenerIds);
        self::assertContains('class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook', $listenerIds);
    }

    public function test_cycles_and_truncation_are_explicit(): void
    {
        $graph = new KnowledgeGraph();
        $a = NodeId::method('Example\A', 'run');
        $b = NodeId::method('Example\B', 'run');
        $graph->addNode(new GraphNode($a, NodeKind::Method, 'A::run', 'Example\A::run', new SourceLocation('app/A.php', 1, 3)));
        $graph->addNode(new GraphNode($b, NodeKind::Method, 'B::run', 'Example\B::run', new SourceLocation('app/B.php', 1, 3)));

        foreach ([[$a, $b, 'app/A.php'], [$b, $a, 'app/B.php']] as [$source, $target, $file]) {
            SemanticEdgeFactory::add($graph, $source, EdgeType::Calls, $target, EvidenceOrigin::StaticAst, 'test', Certainty::Certain, new SourceLocation($file, 2, 2), 'call()');
        }

        $cycle = (new WorkflowBuilder($graph))->build(new WorkflowRequest($a, 10, 10));
        self::assertCount(1, array_filter($cycle->steps, static fn ($step): bool => $step->kind === WorkflowStepKind::Cycle));
        $truncated = (new WorkflowBuilder($graph))->build(new WorkflowRequest($a, 2, 10));
        self::assertTrue($truncated->truncation['truncated']);
        self::assertSame(['method:Example\B::run'], $truncated->truncation['frontier']);
    }

    public function test_command_entrypoint_follows_only_its_resolved_command_class(): void
    {
        $graph = new KnowledgeGraph();
        $firstSignature = NodeId::named(NodeKind::Command, 'first:run');
        $secondSignature = NodeId::named(NodeKind::Command, 'second:run');
        $firstClass = NodeId::symbol(NodeKind::ClassSymbol, 'App\Console\Commands\FirstCommand');
        $secondClass = NodeId::symbol(NodeKind::ClassSymbol, 'App\Console\Commands\SecondCommand');
        $firstHandle = NodeId::method('App\Console\Commands\FirstCommand', 'handle');
        $secondHandle = NodeId::method('App\Console\Commands\SecondCommand', 'handle');

        $graph->addNode(new GraphNode($firstSignature, NodeKind::Command, 'first:run', null, null));
        $graph->addNode(new GraphNode($secondSignature, NodeKind::Command, 'second:run', null, null));
        $graph->addNode(new GraphNode($firstClass, NodeKind::Command, 'FirstCommand', 'App\Console\Commands\FirstCommand', new SourceLocation('app/Console/Commands/FirstCommand.php', 1, 10)));
        $graph->addNode(new GraphNode($secondClass, NodeKind::Command, 'SecondCommand', 'App\Console\Commands\SecondCommand', new SourceLocation('app/Console/Commands/SecondCommand.php', 1, 10)));
        $graph->addNode(new GraphNode($firstHandle, NodeKind::Method, 'handle', 'App\Console\Commands\FirstCommand::handle', new SourceLocation('app/Console/Commands/FirstCommand.php', 6, 9)));
        $graph->addNode(new GraphNode($secondHandle, NodeKind::Method, 'handle', 'App\Console\Commands\SecondCommand::handle', new SourceLocation('app/Console/Commands/SecondCommand.php', 6, 9)));

        foreach ([[$firstSignature, $firstClass], [$secondSignature, $secondClass]] as [$signature, $class]) {
            SemanticEdgeFactory::add(
                $graph,
                $signature,
                EdgeType::ResolvesTo,
                $class,
                EvidenceOrigin::LaravelBoot,
                'command_detector',
                Certainty::Certain,
                null,
                null,
                'command:'.$signature->value,
            );
        }

        $workflow = (new WorkflowBuilder($graph))->build(new WorkflowRequest($firstSignature, 20, 10));
        $nodeIds = array_values(array_filter(array_map(
            static fn ($step): ?string => $step->nodeId?->value,
            $workflow->steps,
        )));

        self::assertContains($firstClass->value, $nodeIds);
        self::assertContains($firstHandle->value, $nodeIds);
        self::assertNotContains($secondClass->value, $nodeIds);
        self::assertNotContains($secondHandle->value, $nodeIds);
    }

    public function test_process_membership_is_precomputed_only_for_stable_framework_entries(): void
    {
        [$graph, $diagnostics, , , , $outputs] = $this->buildSemanticGraph();
        $phase = new BuildProcessMembershipPhase(80, 10);
        $result = $phase->execute(new PipelineContext($graph), [
            'extract_laravel_semantics' => new PhaseResult(
                'extract_laravel_semantics',
                $outputs,
                $diagnostics,
            ),
        ]);
        $records = $result->value;

        self::assertNotEmpty($records);
        self::assertSame($records, array_values($records));
        self::assertNotEmpty(array_filter(
            $graph->nodes(),
            static fn (GraphNode $node): bool => $node->kind === NodeKind::Process,
        ));
        self::assertNotEmpty(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::StepInProcess,
        ));
        self::assertSame([], array_filter(
            $records,
            static fn ($record): bool => $record->nodeId?->value
                === 'method:Fixtures\CommerceApp\Services\OrderService::cancel'
                && str_contains($record->processId->value, 'method:'),
        ), 'Arbitrary method queries must not create precomputed method processes.');

        $processIds = array_values(array_unique(array_map(
            static fn ($record): string => $record->processId->value,
            $records,
        )));
        self::assertNotEmpty(array_filter($processIds, static fn (string $id): bool => str_contains($id, 'route:')));
        self::assertNotEmpty(array_filter($processIds, static fn (string $id): bool => str_contains($id, 'command:')));
        self::assertNotEmpty(array_filter($processIds, static fn (string $id): bool => str_contains($id, 'schedule:')));
        self::assertNotEmpty(array_filter($processIds, static fn (string $id): bool => str_contains($id, 'Jobs')));
        self::assertNotEmpty(array_filter($processIds, static fn (string $id): bool => str_contains($id, 'Events')));
    }
}
