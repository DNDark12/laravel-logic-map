<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class SemanticEdgeFactory
{
    public static function add(
        KnowledgeGraph $graph,
        NodeId $source,
        EdgeType $type,
        NodeId $target,
        EvidenceOrigin $origin,
        string $detector,
        Certainty $certainty,
        ?SourceLocation $location,
        ?string $expression,
        ?string $registrationKey = null,
        ?string $relationIdentity = null,
        array $attributes = [],
        ?string $condition = null,
    ): GraphEdge {
        if ($registrationKey !== null) {
            $attributes['registration_key'] = $registrationKey;
        }

        if ($relationIdentity !== null) {
            $attributes['semantic_relation_key'] = hash('sha256', implode("\0", [
                $source->value,
                $type->value,
                $target->value,
                self::normalize($relationIdentity),
            ]));
        }

        $edge = GraphEdge::fromEvidence(
            $source,
            $target,
            $type,
            new EvidenceRecord(
                $origin,
                $detector,
                $certainty,
                $location,
                $expression,
                $condition,
                $attributes,
            ),
        );
        $graph->addEdge($edge);

        return $edge;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
