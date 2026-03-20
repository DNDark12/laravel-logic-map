<?php

namespace dndark\LogicMap\Domain\Trace;

use Illuminate\Contracts\Support\Arrayable;

class BranchPoint implements Arrayable
{
    /**
     * @param string[] $branches The target nodes this point branches out to
     */
    public function __construct(
        public readonly string $node_id,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $outgoing_count,
        public readonly array $branches,
        public readonly string $why_included,
    ) {
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->node_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'outgoing_count' => $this->outgoing_count,
            'branches' => $this->branches,
            'why_included' => $this->why_included,
        ];
    }
}
