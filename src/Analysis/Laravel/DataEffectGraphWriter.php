<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Facts\DataEffectFact;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class DataEffectGraphWriter
{
    public function table(KnowledgeGraph $graph, string $table): NodeId
    {
        $id = NodeId::named(NodeKind::Table, $table);

        if (! $graph->hasNode($id)) {
            $graph->addNode(new GraphNode($id, NodeKind::Table, $table, null, null));
        }

        return $id;
    }

    public function column(KnowledgeGraph $graph, string $table, string $column): NodeId
    {
        $key = $table.'.'.$column;
        $id = NodeId::named(NodeKind::Column, $key);

        if (! $graph->hasNode($id)) {
            $graph->addNode(new GraphNode(
                $id,
                NodeKind::Column,
                $column,
                null,
                null,
                ['table' => $table],
            ));
        }

        return $id;
    }

    public function emit(
        KnowledgeGraph $graph,
        string $source,
        EdgeType $effect,
        NodeId $target,
        string $file,
        int $startLine,
        int $endLine,
        string $expression,
        string $detector,
        string $resourceType,
        string $resource,
        Certainty $certainty = Certainty::Certain,
        array $attributes = [],
        array $controlContexts = [],
    ): DataEffectFact {
        $sourceId = NodeId::fromString($source);
        SemanticEdgeFactory::add(
            $graph,
            $sourceId,
            $effect,
            $target,
            EvidenceOrigin::StaticAst,
            $detector,
            $certainty,
            new SourceLocation($file, $startLine, $endLine),
            $expression,
            null,
            null,
            ['resource_type' => $resourceType, 'resource' => $resource, ...$attributes],
        );

        return new DataEffectFact(
            $file,
            $startLine,
            $endLine,
            $source,
            $effect->value,
            $resourceType,
            $resource,
            $certainty,
            $attributes,
            $controlContexts,
        );
    }
}
