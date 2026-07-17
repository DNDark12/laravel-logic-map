<?php

namespace DNDark\LogicMap\Tests\Unit\Domain\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\TransactionSegment;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    public function test_definition_enforces_references_terminals_boundaries_and_summary_counts(): void
    {
        $steps = [
            new WorkflowStep('entry', WorkflowStepKind::Entry, 'Order cancel', NodeId::route('POST', 'orders/{order}/cancel'), 'Orders', ['e1']),
            new WorkflowStep('decision', WorkflowStepKind::Decision, 'Can cancel?', null, 'Orders', ['e2']),
            new WorkflowStep('effect', WorkflowStepKind::Effect, 'orders.status', NodeId::named(\DNDark\LogicMap\Domain\Graph\NodeKind::Column, 'orders.status'), 'Orders', ['e3']),
            new WorkflowStep('async', WorkflowStepKind::AsyncBoundary, 'Queue', null, 'Integration', ['e4']),
            new WorkflowStep('terminal', WorkflowStepKind::Terminal, 'Rejected', null, 'Orders', ['e5']),
        ];
        $transitions = [
            new WorkflowTransition('entry', 'decision', ExecutionBoundary::Sync, null, null, false, ['e1']),
            new WorkflowTransition('decision', 'effect', ExecutionBoundary::Sync, '!blocked', 'success', false, ['e2']),
            new WorkflowTransition('decision', 'terminal', ExecutionBoundary::Sync, 'blocked', 'failure', false, ['e2']),
            new WorkflowTransition('effect', 'async', ExecutionBoundary::Async, null, null, false, ['e4']),
        ];
        $definition = new WorkflowDefinition(
            WorkflowId::fromEntry(NodeId::route('POST', 'orders/{order}/cancel')),
            NodeId::route('POST', 'orders/{order}/cancel'),
            'entry',
            $steps,
            $transitions,
            [new TransactionSegment('tx:1', ['effect'], ['e3'])],
        );
        $summary = $definition->summary();

        self::assertSame(2, $summary->moduleCount);
        self::assertSame(1, $summary->branchCount);
        self::assertSame(1, $summary->asyncBoundaryCount);
        self::assertSame(1, $summary->transactionCount);
        self::assertSame(1, $summary->effectCount);
        self::assertSame(0, $summary->gapCount);
    }

    public function test_invalid_duplicate_missing_decision_cycle_and_terminal_contracts_are_rejected(): void
    {
        $entry = new WorkflowStep('a', WorkflowStepKind::Entry, 'A', null, null, ['e']);
        $terminal = new WorkflowStep('z', WorkflowStepKind::Terminal, 'Z', null, null, ['e']);

        foreach ([
            fn () => new WorkflowDefinition(new WorkflowId('workflow:x'), NodeId::fromString('method:App\\A::x'), 'a', [$entry, $entry], [], []),
            fn () => new WorkflowDefinition(new WorkflowId('workflow:x'), NodeId::fromString('method:App\\A::x'), 'a', [$entry], [new WorkflowTransition('a', 'missing', ExecutionBoundary::Sync, null, null, false, ['e'])], []),
            fn () => new WorkflowDefinition(new WorkflowId('workflow:x'), NodeId::fromString('method:App\\A::x'), 'a', [$entry, $terminal], [new WorkflowTransition('z', 'a', ExecutionBoundary::Sync, null, null, false, ['e'])], []),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid workflow definition was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertTrue((new WorkflowTransition('a', 'a', ExecutionBoundary::Sync, null, null, true, ['e']))->isCycle);
        self::assertSame(ExecutionBoundary::Async, ExecutionBoundary::from('async'));
    }
}
