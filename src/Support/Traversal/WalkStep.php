<?php

namespace dndark\LogicMap\Support\Traversal;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Node;

/**
 * A single step produced by GraphWalker during BFS traversal.
 */
class WalkStep
{
    public function __construct(
        public readonly Node    $node,
        public readonly int     $depth,
        public readonly ?Edge   $incomingEdge,
        public readonly bool    $asyncBoundary,
    ) {
    }
}
