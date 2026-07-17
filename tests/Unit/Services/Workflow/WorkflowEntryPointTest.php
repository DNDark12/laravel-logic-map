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
