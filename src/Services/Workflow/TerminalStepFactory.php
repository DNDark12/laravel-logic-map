<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Domain\Graph\GraphReader;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class TerminalStepFactory
{
    public function make(ThrowFact|EarlyReturnFact $fact, ?string $module, GraphReader $graph): WorkflowStep
    {
        $exception = $fact instanceof ThrowFact ? $fact->exceptionClass : null;
        $nodeId = null;

        if ($exception !== null) {
            $candidate = NodeId::fromString('class:'.ltrim($exception, '\\'));

            if ($graph->hasNode($candidate)) {
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
