<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class TerminalStepFactory
{
    /** @param array<string, GraphNode> $nodes */
    public function make(ThrowFact|EarlyReturnFact $fact, ?string $module, array $nodes): WorkflowStep
    {
        $exception = $fact instanceof ThrowFact ? $fact->exceptionClass : null;
        $nodeId = null;

        if ($exception !== null) {
            $candidate = NodeId::fromString('class:'.ltrim($exception, '\\'));

            if (isset($nodes[$candidate->value])) {
                $nodeId = $candidate;
            }
        }

        return new WorkflowStep(
            $fact->boundaryId,
            WorkflowStepKind::Terminal,
            $exception ?? ($fact->expression ?? 'return'),
            $nodeId,
            $module,
            [],
            ['control_contexts' => $fact->controlContexts],
        );
    }
}
