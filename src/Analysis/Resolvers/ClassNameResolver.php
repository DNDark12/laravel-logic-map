<?php

namespace dndark\LogicMap\Analysis\Resolvers;

use PhpParser\Node;
use PhpParser\Node\Name;

class ClassNameResolver
{
    /**
     * Resolve the full class name from a name node and current namespace/uses.
     * (Simplified version, php-parser already provides NameResolver visitor).
     */
    public function resolve(Name $name): string
    {
        return $name->toString();
    }
}
