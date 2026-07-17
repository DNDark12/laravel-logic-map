<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Facts\ExternalEffectFact;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class ExternalEffectGraphBuilder
{
    public function build(array $facts, KnowledgeGraph $graph): void
    {
        foreach ($facts as $fact) {
            if (! $fact instanceof ExternalEffectFact) {
                continue;
            }

            $kind = $this->kind($fact->resourceType);
            $target = NodeId::named($kind, $fact->resource);

            if (! $graph->hasNode($target)) {
                $graph->addNode(new GraphNode(
                    $target,
                    $kind,
                    $fact->resource,
                    null,
                    null,
                    ['resource_type' => $fact->resourceType],
                ));
            }

            SemanticEdgeFactory::add(
                $graph,
                NodeId::fromString($fact->enclosingSymbol),
                EdgeType::from($fact->effect),
                $target,
                EvidenceOrigin::StaticAst,
                $this->detector($fact->resourceType),
                $fact->certainty,
                new SourceLocation($fact->file, $fact->startLine, $fact->endLine),
                $fact->effect.'('.$fact->resource.')',
                null,
                null,
                ['resource_type' => $fact->resourceType, 'resource' => $fact->resource, ...$fact->attributes],
            );
        }
    }

    private function kind(string $resourceType): NodeKind
    {
        return match ($resourceType) {
            'cache_key' => NodeKind::CacheKey,
            'config_key' => NodeKind::ConfigKey,
            'storage_path' => NodeKind::StoragePath,
            'view' => NodeKind::View,
            'external_endpoint' => NodeKind::ExternalEndpoint,
        };
    }

    private function detector(string $resourceType): string
    {
        return match ($resourceType) {
            'cache_key' => 'cache_effect_detector',
            'config_key' => 'config_effect_detector',
            'storage_path' => 'storage_effect_detector',
            'view' => 'view_effect_detector',
            'external_endpoint' => 'http_client_effect_detector',
        };
    }
}
