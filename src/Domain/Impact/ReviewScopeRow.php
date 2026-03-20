<?php

namespace dndark\LogicMap\Domain\Impact;

use Illuminate\Contracts\Support\Arrayable;

class ReviewScopeRow implements Arrayable
{
    public function __construct(
        public readonly string $node_id,
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $module,
        public readonly ?string $risk,
        public readonly ?int $risk_score,
        public readonly string $coverage_level,
        public readonly ?int $depth,
        public readonly string $why_included,
    ) {
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->node_id,
            'kind' => $this->kind,
            'name' => $this->name,
            'module' => $this->module,
            'risk' => $this->risk,
            'risk_score' => $this->risk_score,
            'coverage_level' => $this->coverage_level,
            'depth' => $this->depth,
            'why_included' => $this->why_included,
        ];
    }
}
