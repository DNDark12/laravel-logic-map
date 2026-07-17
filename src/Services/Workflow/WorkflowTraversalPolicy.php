<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\EdgeType;

final readonly class WorkflowTraversalPolicy
{
    public function __construct(private EdgeDirectionPolicy $directions = new EdgeDirectionPolicy()) {}

    public function includes(EdgeType $type): bool
    {
        return $this->directions->workflow($type);
    }
}
