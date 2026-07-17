<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\BranchConditionFact;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class DecisionStepFactory
{
    public function make(BranchConditionFact $branch, ?string $module): WorkflowStep
    {
        return new WorkflowStep(
            'decision:'.hash('sha256', implode("\0", [$branch->file, $branch->startLine, $branch->expression])),
            WorkflowStepKind::Decision,
            $branch->expression,
            null,
            $module,
            [],
            ['branch' => $branch->branch, 'control_contexts' => $branch->controlContexts],
        );
    }
}
