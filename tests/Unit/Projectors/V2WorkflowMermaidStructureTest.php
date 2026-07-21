<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\TransactionSegment;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowGap;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use DNDark\LogicMap\Projectors\WorkflowMermaidProjector;
use PHPUnit\Framework\TestCase;

final class V2WorkflowMermaidStructureTest extends TestCase
{
    public function test_mermaid_is_escaped_balanced_and_uses_workflow_semantics(): void
    {
        $workflow = $this->fixture();
        $diagram = (new WorkflowMermaidProjector())->project($workflow);
        file_put_contents(sys_get_temp_dir().'/logic-map-v2-order-cancel.mmd', $diagram);

        self::assertStringStartsWith('flowchart', $diagram);
        self::assertStringNotContainsString('"quoted"', $diagram);
        self::assertStringNotContainsString("line\nbreak", $diagram);
        self::assertStringNotContainsString('`tick`', $diagram);
        self::assertStringContainsString('&quot;quoted&quot;', $diagram);
        self::assertStringContainsString('line<br/>break', $diagram);
        self::assertStringContainsString('&#96;tick&#96;', $diagram);
        self::assertSame(substr_count($diagram, 'subgraph '), substr_count($diagram, "\n  end"));
        self::assertStringContainsString('subgraph module_', $diagram);
        self::assertStringContainsString('subgraph txn_', $diagram);
        self::assertStringContainsString('[(', $diagram);
        self::assertStringContainsString(' -. ', $diagram);
        self::assertStringContainsString('Decision:', $diagram);
        self::assertStringContainsString('Terminal:', $diagram);
        self::assertStringContainsString('Cycle:', $diagram);
        self::assertStringContainsString('Gap:', $diagram);

        preg_match_all('/^\s*(n\d+)(?:\(|\[|\{)/m', $diagram, $declared);
        $aliases = array_values(array_unique($declared[1]));
        preg_match_all('/^\s*(n\d+)\s+(?:-->|-.+?\.->)\s+(n\d+)/m', $diagram, $transitions, PREG_SET_ORDER);
        self::assertCount(count($workflow->steps), $aliases);

        foreach ($transitions as $transition) {
            self::assertContains($transition[1], $aliases);
            self::assertContains($transition[2], $aliases);
            self::assertLessThan(
                strpos($diagram, $transition[0]),
                strpos($diagram, $transition[1]),
                $transition[0],
            );
        }
    }

    private function fixture(): WorkflowDefinition
    {
        $steps = [
            new WorkflowStep('a-entry', WorkflowStepKind::Entry, 'Cancel [order] "quoted"', NodeId::route('POST', 'orders/cancel'), 'Orders', ['ev']),
            new WorkflowStep('b-decision', WorkflowStepKind::Decision, "line\nbreak {guard}", null, 'Orders', ['ev']),
            new WorkflowStep('c-effect', WorkflowStepKind::Effect, 'orders.status `tick`', NodeId::fromString('column:orders.status'), 'Orders', ['ev']),
            new WorkflowStep('d-async', WorkflowStepKind::AsyncBoundary, 'Queue webhook', null, 'Integration', ['ev']),
            new WorkflowStep('e-terminal', WorkflowStepKind::Terminal, 'OrderCannotBeCancelled', null, 'Orders', ['ev']),
            new WorkflowStep('f-cycle', WorkflowStepKind::Cycle, 'Retry loop', null, 'Integration', ['ev']),
            new WorkflowStep('g-gap', WorkflowStepKind::Gap, 'Dynamic receiver', null, null, ['ev']),
        ];

        return new WorkflowDefinition(
            new WorkflowId('workflow:mermaid'),
            NodeId::route('POST', 'orders/cancel'),
            'a-entry',
            $steps,
            [
                new WorkflowTransition('a-entry', 'b-decision', ExecutionBoundary::Sync, null, null, false, ['ev']),
                new WorkflowTransition('b-decision', 'c-effect', ExecutionBoundary::Sync, 'can cancel', 'true', false, ['ev']),
                new WorkflowTransition('b-decision', 'e-terminal', ExecutionBoundary::Sync, 'cannot cancel', 'false', false, ['ev']),
                new WorkflowTransition('c-effect', 'd-async', ExecutionBoundary::Async, null, null, false, ['ev']),
                new WorkflowTransition('d-async', 'f-cycle', ExecutionBoundary::Async, null, null, false, ['ev']),
                new WorkflowTransition('f-cycle', 'g-gap', ExecutionBoundary::Sync, null, null, true, ['ev']),
            ],
            [new TransactionSegment('transaction:cancel', ['c-effect'], ['ev'])],
            [new WorkflowGap('g-gap', 'Dynamic receiver', ['ev'])],
        );
    }
}
