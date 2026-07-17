<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class TerminalStepFactory
{
    public function make(ThrowFact|EarlyReturnFact $fact, ?string $module): WorkflowStep
    {
        $exception = $fact instanceof ThrowFact ? $fact->exceptionClass : null;

        return new WorkflowStep(
            $fact->boundaryId,
            WorkflowStepKind::Terminal,
            $exception ?? ($fact->expression ?? 'return'),
            $exception === null ? null : NodeId::fromString('class:'.ltrim($exception, '\\')),
            $module,
            [],
            ['control_contexts' => $fact->controlContexts],
        );
    }
}
