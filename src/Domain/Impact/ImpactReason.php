<?php

namespace DNDark\LogicMap\Domain\Impact;

use DNDark\LogicMap\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ImpactReason
{
    public function __construct(
        public ImpactCategory $category,
        public ImpactLevel $level,
        public array $nodeChain,
        public array $edgeChain,
        public array $evidenceIds,
        public string $sentence,
    ) {
        if ($nodeChain === [] || count($edgeChain) !== count($nodeChain) - 1) {
            throw new InvalidArgumentException('Impact reasons require one ordered edge between each pair of nodes.');
        }

        foreach ([...$nodeChain, ...$edgeChain, ...$evidenceIds] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Impact reason chains and evidence IDs must be non-empty strings.');
            }
        }

        if ($evidenceIds === [] || trim($sentence) === '') {
            throw new InvalidArgumentException('Impact reasons require evidence and deterministic explanatory copy.');
        }
    }

    public function key(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'level' => $this->level->value,
            'node_chain' => $this->nodeChain,
            'edge_chain' => $this->edgeChain,
            'evidence_ids' => $this->evidenceIds,
            'sentence' => $this->sentence,
        ];
    }
}
