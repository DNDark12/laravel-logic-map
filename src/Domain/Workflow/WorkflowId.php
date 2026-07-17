<?php

namespace DNDark\LogicMap\Domain\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class WorkflowId
{
    public function __construct(public string $value)
    {
        if (! str_starts_with($value, 'workflow:') || strlen($value) <= strlen('workflow:')) {
            throw new InvalidArgumentException('Workflow IDs require a workflow: prefix.');
        }
    }

    public static function fromEntry(NodeId $entry): self
    {
        return new self('workflow:'.hash('sha256', $entry->value));
    }
}
