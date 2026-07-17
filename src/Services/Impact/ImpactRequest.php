<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use InvalidArgumentException;

final readonly class ImpactRequest
{
    public function __construct(
        public array $changedSymbols,
        public int $maxNodes,
        public int $maxEdges,
        public int $maxDepth,
        public int $maxResponseBytes,
    ) {
        foreach ($changedSymbols as $symbol) {
            if (! $symbol instanceof ChangedSymbol) {
                throw new InvalidArgumentException('Impact requests require ChangedSymbol values.');
            }
        }

        if ($changedSymbols === [] || min($maxNodes, $maxEdges, $maxDepth, $maxResponseBytes) < 1) {
            throw new InvalidArgumentException('Impact requests require changes and positive shared query limits.');
        }
    }
}
