<?php

namespace DNDark\LogicMap\Analysis\Facts;

use PhpParser\NodeVisitor;

interface FactCollector extends NodeVisitor
{
    public function name(): string;

    /** @return list<SemanticFact> */
    public function facts(): array;
}
