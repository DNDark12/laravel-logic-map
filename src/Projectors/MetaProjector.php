<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Edge;
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
        $moduleByNode = [];
        $modules = [];

        foreach ($nodes as $node) {
            // Count by kind
            $kindValue = $node->kind->value;
            $kinds[$kindValue] = ($kinds[$kindValue] ?? 0) + 1;

            $module = $node->metadata['module'] ?? ModuleExtractor::moduleOf($node->id);
            $moduleByNode[$node->id] = $module;
            $modules[$module] = ($modules[$module] ?? 0) + 1;

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
        arsort($modules);
        $crossModuleEdges = $this->buildCrossModuleEdges($moduleByNode, $edges);

        return [
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'kinds' => $kinds,
            'edge_types' => $edgeTypes,
            'confidence_distribution' => $confidenceLevels,
            'namespaces' => array_slice($namespaces, 0, 20, true), // Top 20 namespaces
            'modules' => array_slice($modules, 0, 20, true),
            'cross_module_edges' => $crossModuleEdges,
            'available_kinds' => array_map(fn($k) => $k->value, NodeKind::cases()),
            'available_edge_types' => array_map(fn($e) => $e->value, EdgeType::cases()),
            'available_confidence_levels' => array_map(fn($c) => $c->value, Confidence::cases()),
        ];
    }

    /**
     * @param array<string, string> $moduleByNode
     * @param array<int, Edge> $edges
     * @return array<int, array{source_module: string, target_module: string, count: int}>
     */
    protected function buildCrossModuleEdges(array $moduleByNode, array $edges): array
    {
        $counts = [];

        foreach ($edges as $edge) {
            $sourceModule = $moduleByNode[$edge->source] ?? ModuleExtractor::moduleOf($edge->source);
            $targetModule = $moduleByNode[$edge->target] ?? ModuleExtractor::moduleOf($edge->target);

            if ($sourceModule === '' || $targetModule === '' || $sourceModule === $targetModule) {
                continue;
            }

            $key = $sourceModule . '>>>' . $targetModule;
            $counts[$key] = [
                'source_module' => $sourceModule,
                'target_module' => $targetModule,
                'count' => ($counts[$key]['count'] ?? 0) + 1,
            ];
        }

        $result = array_values($counts);
        usort($result, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return ($a['source_module'] . $a['target_module']) <=> ($b['source_module'] . $b['target_module']);
        });

        return $result;
    }
}
