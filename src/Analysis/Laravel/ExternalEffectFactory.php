<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Facts\ExternalEffectFact;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;

final class ExternalEffectFactory
{
    public static function make(
        SemanticFact $source,
        EdgeType $effect,
        string $resourceType,
        string $resource,
        Certainty $certainty = Certainty::Certain,
        array $attributes = [],
    ): ExternalEffectFact {
        return new ExternalEffectFact(
            $source->file,
            $source->startLine,
            $source->endLine,
            $source->attributes['enclosing_symbol'],
            $effect->value,
            $resourceType,
            $resource,
            $certainty,
            $attributes,
            $source->controlContexts,
        );
    }
}
