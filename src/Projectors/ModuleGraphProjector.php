<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class ModuleGraphProjector
{
    public function project(KnowledgeGraph $graph): array
    {
        $memberships = [];
        $moduleNames = [];

        foreach ($graph->nodes() as $node) {
            if ($node->kind === NodeKind::Module) {
                $moduleNames[$node->id->value] = $node->name;
            }
        }

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule && isset($moduleNames[$edge->target->value])) {
                $memberships[$edge->source->value] = $moduleNames[$edge->target->value];
            }
        }

        $aggregates = [];
        $excluded = [EdgeType::Contains, EdgeType::Defines, EdgeType::MemberOfModule];

        foreach ($graph->edges() as $edge) {
            if (in_array($edge->type, $excluded, true)) {
                continue;
            }

            $source = $memberships[$edge->source->value] ?? null;
            $target = $memberships[$edge->target->value] ?? null;

            if ($source === null || $target === null || $source === $target) {
                continue;
            }

            $key = implode("\0", [$source, $target, $edge->type->value]);
            $aggregates[$key] ??= [
                'source_module' => $source,
                'target_module' => $target,
                'type' => $edge->type->value,
                'edge_count' => 0,
                'evidence_count' => 0,
            ];
            $aggregates[$key]['edge_count']++;
            $aggregates[$key]['evidence_count'] += count($edge->evidence);
        }

        ksort($moduleNames, SORT_STRING);
        ksort($aggregates, SORT_STRING);

        return [
            'nodes' => array_values(array_map(
                static fn (string $id, string $name): array => ['id' => $id, 'name' => $name],
                array_keys($moduleNames),
                array_values($moduleNames),
            )),
            'edges' => array_values($aggregates),
        ];
    }
}
