<?php

namespace dndark\LogicMap\Domain;

/**
 * Read-model DTO for GET /logic-map/trace/{id}.
 *
 * This artifact is a query-time projection over canonical graph data.
 * It must NOT be embedded into canonical graph payloads.
 */
class WorkflowTraceReport
{
    public function __construct(
        public readonly array $target,
        public readonly array $summary,
        public readonly array $segments,
        public readonly array $branchPoints,
        public readonly array $entrypoints,
        public readonly array $persistenceTouchpoints,
    ) {
    }

    public function toArray(): array
    {
        return [
            'target'                  => $this->target,
            'summary'                 => $this->summary,
            'segments'                => $this->segments,
            'branch_points'           => $this->branchPoints,
            'entrypoints'             => $this->entrypoints,
            'persistence_touchpoints' => $this->persistenceTouchpoints,
        ];
    }
}
