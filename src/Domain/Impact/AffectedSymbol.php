<?php

namespace DNDark\LogicMap\Domain\Impact;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class AffectedSymbol
{
    public array $reasons;

    public function __construct(public NodeId $nodeId, array $reasons)
    {
        $unique = [];

        foreach ($reasons as $reason) {
            if (! $reason instanceof ImpactReason) {
                throw new InvalidArgumentException('Affected symbol reasons must contain ImpactReason values.');
            }

            $unique[$reason->key()] = $reason;
        }

        if ($unique === []) {
            throw new InvalidArgumentException('Affected symbols require at least one reason.');
        }

        uasort($unique, static fn (ImpactReason $left, ImpactReason $right): int => [
            $left->category->value,
            $left->level->value,
            implode("\0", $left->nodeChain),
            implode("\0", $left->edgeChain),
        ] <=> [
            $right->category->value,
            $right->level->value,
            implode("\0", $right->nodeChain),
            implode("\0", $right->edgeChain),
        ]);
        $this->reasons = array_values($unique);
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId->value,
            'reasons' => array_map(static fn (ImpactReason $reason): array => $reason->toArray(), $this->reasons),
        ];
    }
}
