<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class WorkflowRequest
{
    public function __construct(
        public NodeId $entrypoint,
        public int $maxSteps,
        public int $maxDepth,
    ) {
        if ($maxSteps < 1 || $maxDepth < 1) {
            throw new InvalidArgumentException('Workflow limits must be positive.');
        }
    }
}
