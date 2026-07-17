<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class EffectStepFactory
{
    public function make(GraphNode $node, GraphEdge $edge, ?string $module): WorkflowStep
    {
        return new WorkflowStep(
            'step:'.hash('sha256', $node->id->value),
            WorkflowStepKind::Effect,
            $node->name,
            $node->id,
            $module,
            array_map(static fn ($evidence): string => $evidence->id(), $edge->evidence),
            ['edge_type' => $edge->type->value],
        );
    }
}
