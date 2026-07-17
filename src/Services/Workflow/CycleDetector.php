<?php

namespace DNDark\LogicMap\Services\Workflow;

final class CycleDetector
{
    private array $visited = [];

    public function visit(string $nodeId): bool
    {
        if (isset($this->visited[$nodeId])) {
            return false;
        }

        $this->visited[$nodeId] = true;

        return true;
    }
}
