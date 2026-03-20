<?php

namespace dndark\LogicMap\Domain;

/**
 * Read-model DTO for GET /logic-map/impact/{id}.
 *
 * This artifact is a query-time projection over canonical graph data
 * and derived analysis. It must NOT be embedded into canonical graph payloads.
 */
class ChangeImpactReport
{
    public function __construct(
        public readonly array  $target,
        public readonly array  $summary,
        public readonly array  $upstream,
        public readonly array  $downstream,
        public readonly array  $criticalTouches,
        public readonly array  $reviewScope,
    ) {
    }

    public function toArray(): array
    {
        return [
            'target'          => $this->target,
            'summary'         => $this->summary,
            'upstream'        => $this->upstream,
            'downstream'      => $this->downstream,
            'critical_touches' => $this->criticalTouches,
            'review_scope'    => $this->reviewScope,
        ];
    }
}
