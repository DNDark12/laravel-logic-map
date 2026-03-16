<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;

class MetaProjector
{
    public function getMeta(Graph $graph): array
    {
        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();

        $kinds = [];
        $namespaces = [];
        $edgeTypes = [];
        $confidenceLevels = [];

        foreach ($nodes as $node) {
            // Count by kind
            $kindValue = $node->kind->value;
            $kinds[$kindValue] = ($kinds[$kindValue] ?? 0) + 1;

            // Extract namespace
            if ($node->name && str_contains($node->name, '\\')) {
                $parts = explode('\\', $node->name);
                array_pop($parts); // Remove class name
                $namespace = implode('\\', $parts);
                if ($namespace) {
                    $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
                }
            }
        }

        foreach ($edges as $edge) {
            $typeValue = $edge->type->value;
            $edgeTypes[$typeValue] = ($edgeTypes[$typeValue] ?? 0) + 1;

            $confValue = $edge->confidence->value;
            $confidenceLevels[$confValue] = ($confidenceLevels[$confValue] ?? 0) + 1;
        }

        // Sort namespaces by count
        arsort($namespaces);

        return [
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'kinds' => $kinds,
            'edge_types' => $edgeTypes,
            'confidence_distribution' => $confidenceLevels,
            'namespaces' => array_slice($namespaces, 0, 20, true), // Top 20 namespaces
            'available_kinds' => array_map(fn($k) => $k->value, NodeKind::cases()),
            'available_edge_types' => array_map(fn($e) => $e->value, EdgeType::cases()),
            'available_confidence_levels' => array_map(fn($c) => $c->value, Confidence::cases()),
        ];
    }
}
