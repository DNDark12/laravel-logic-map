<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\Phases\BuildProcessMembershipPhase;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Projectors\WorkflowJsonProjector;
use DNDark\LogicMap\Services\Workflow\WorkflowBuilder;
use DNDark\LogicMap\Services\Workflow\WorkflowRequest;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class V2WorkflowAccuracyGateTest extends CommerceFixtureTestCase
{
    public function test_gate_c_locks_the_complete_order_cancellation_workflow(): void
    {
        self::assertSame('2.0.0', AnalysisVersion::CURRENT);
        [$graph, $diagnostics, , , , $outputs] = $this->buildSemanticGraph();
        $process = (new BuildProcessMembershipPhase(200, 20))->execute(
            new PipelineContext($graph),
            ['extract_laravel_semantics' => new PhaseResult(
                'extract_laravel_semantics',
                $outputs,
                $diagnostics,
            )],
        );
        self::assertNotEmpty($process->value);

        $entrypoint = NodeId::route('POST', 'orders/{order}/cancel');
        $workflow = (new WorkflowBuilder($graph, $outputs, $diagnostics))->build(
            new WorkflowRequest($entrypoint, 200, 20),
        );
        $steps = [];

        foreach ($workflow->steps as $step) {
            $steps[$step->id] = $step;
        }

        $decision = array_values(array_filter(
            $workflow->steps,
            static fn ($step): bool => $step->kind === WorkflowStepKind::Decision
                && str_contains($step->label, 'canBeCancelled'),
        ));
        $terminal = array_values(array_filter(
            $workflow->steps,
            static fn ($step): bool => $step->kind === WorkflowStepKind::Terminal
                && str_contains($step->label, 'OrderCannotBeCancelledException'),
        ));
        self::assertNotEmpty($decision);
        self::assertNotEmpty($terminal);
        self::assertSame([], array_values(array_filter(
            $workflow->transitions,
            static fn ($transition): bool => $transition->from === $terminal[0]->id,
        )));

        self::assertCount(1, $workflow->transactions);
        $transactionNodes = array_values(array_filter(array_map(
            static fn (string $stepId): ?string => $steps[$stepId]->nodeId?->value,
            $workflow->transactions[0]->stepIds,
        )));
        self::assertContains('column:orders.status', $transactionNodes);
        self::assertContains('column:inventory_stocks.quantity', $transactionNodes);

        $webhookSteps = array_values(array_filter(
            $workflow->steps,
            static fn ($step): bool => $step->nodeId?->value === 'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook',
        ));
        self::assertNotEmpty($webhookSteps);
        $incomingWebhook = array_values(array_filter(
            $workflow->transitions,
            static fn ($transition): bool => $transition->to === $webhookSteps[0]->id,
        ));
        self::assertNotEmpty($incomingWebhook);
        self::assertContains(
            ExecutionBoundary::Async,
            array_map(static fn ($transition) => $transition->boundary, $incomingWebhook),
        );

        $evidenceIds = [];

        foreach ($workflow->steps as $step) {
            foreach ($step->evidenceIds as $id) {
                $evidenceIds[$id] = true;
            }
        }

        foreach ($workflow->transitions as $transition) {
            foreach ($transition->evidenceIds as $id) {
                $evidenceIds[$id] = true;
            }
        }

        $evidence = array_values(array_filter(
            $graph->evidence(),
            static fn ($record): bool => isset($evidenceIds[$record->id()]),
        ));
        $projection = (new WorkflowJsonProjector())->project($workflow, 'commerce-fixture', $evidence);
        $json = json_encode(
            $projection,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        $golden = dirname(__DIR__).'/Golden/workflows/order-cancel.json';

        self::assertFileExists($golden);
        self::assertSame(file_get_contents($golden), $json);
    }
}
