<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Services\Workflow\WorkflowBuilder;
use DNDark\LogicMap\Services\Workflow\WorkflowRequest;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class WorkflowBuilderTest extends CommerceFixtureTestCase
{
    public function test_cancellation_route_contains_branch_terminal_transaction_effects_and_async_boundary(): void
    {
        [$graph, $diagnostics, , , , $outputs] = $this->buildSemanticGraph();
        $workflow = (new WorkflowBuilder($graph, $outputs, $diagnostics))->build(
            new WorkflowRequest(NodeId::route('POST', 'orders/{order}/cancel'), 100, 12),
        );
        $nodeIds = array_values(array_filter(array_map(
            static fn ($step): ?string => $step->nodeId?->value,
            $workflow->steps,
        )));

        foreach ([
            'route:POST:orders/{order}/cancel',
            'middleware:auth',
            'class:Fixtures\CommerceApp\Http\Requests\CancelOrderRequest',
            'method:Fixtures\CommerceApp\Policies\OrderPolicy::cancel',
            'method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel',
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            'column:orders.status',
            'column:inventory_stocks.quantity',
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
            'class:Fixtures\CommerceApp\Listeners\RestockInventory',
            'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook',
            'external:{config:services.erp.base_url}/orders/{id}/cancel',
            'cache:order-summary:{id}',
            'class:Fixtures\CommerceApp\Notifications\OrderWasCancelled',
        ] as $expected) {
            self::assertContains($expected, $nodeIds, $expected);
        }

        $decisions = array_values(array_filter($workflow->steps, static fn ($step): bool => $step->kind === WorkflowStepKind::Decision));
        $terminals = array_values(array_filter($workflow->steps, static fn ($step): bool => $step->kind === WorkflowStepKind::Terminal));
        $boundaries = array_values(array_filter($workflow->steps, static fn ($step): bool => $step->kind === WorkflowStepKind::AsyncBoundary));
        self::assertNotEmpty($decisions);
        self::assertStringContainsString('canBeCancelled', $decisions[0]->label);
        self::assertNotEmpty(array_filter($terminals, static fn ($step): bool => str_contains($step->label, 'OrderCannotBeCancelledException')));
        self::assertNotEmpty($boundaries);
        self::assertCount(1, $workflow->transactions);
        $transactionNodes = array_values(array_filter(array_map(
            static fn ($step): ?string => in_array($step->id, $workflow->transactions[0]->stepIds, true)
                ? $step->nodeId?->value
                : null,
            $workflow->steps,
        )));
        self::assertContains('column:orders.status', $transactionNodes);
        self::assertContains('column:inventory_stocks.quantity', $transactionNodes);

        foreach ($terminals as $terminal) {
            self::assertSame([], array_values(array_filter(
                $workflow->transitions,
                static fn ($transition): bool => $transition->from === $terminal->id,
            )));
        }
    }
}
