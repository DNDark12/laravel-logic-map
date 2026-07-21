<?php

namespace DNDark\LogicMap\Services\Query;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Support\NodeIdCodec;

final readonly class SymbolContextService
{
    public function __construct(
        private NodeIdCodec $codec,
        private int $maxEdges,
        private ?RuntimeEvidenceMerger $runtime = null,
    ) {
    }

    public function context(GraphSnapshot $snapshot, NodeId $id, ?array $selectedSessionIds = null): ?array
    {
        $symbol = $snapshot->graph->findNode($id);

        if ($symbol === null) {
            return null;
        }

        $runtime = $this->runtime?->merge($snapshot, $selectedSessionIds, [$id->value]);
        $overlays = $runtime['overlays'] ?? [];
        $runtimeRelations = [];

        foreach ($runtime['relations'] ?? [] as $relation) {
            $runtimeRelations[$relation['relation_key']] = $relation;
        }

        $incoming = $this->relationEdges($snapshot, $id, false, $overlays);
        $outgoing = $this->relationEdges($snapshot, $id, true, $overlays);
        $all = [...$incoming, ...$outgoing];
        $truncated = count($all) > $this->maxEdges;
        $remaining = $this->maxEdges;
        $incoming = array_slice($incoming, 0, $remaining);
        $remaining -= count($incoming);
        $outgoing = array_slice($outgoing, 0, max(0, $remaining));

        // Fetch only the nodes the bounded edge set references.
        $endpointIds = [$id->value => true];

        foreach ([...$incoming, ...$outgoing] as $edge) {
            $endpointIds[$edge->source->value] = true;
            $endpointIds[$edge->target->value] = true;
        }

        $nodes = $snapshot->graph->nodesByIds(array_keys($endpointIds));
        $nodes[$id->value] = $symbol;
        $evidence = [];

        foreach ([...$incoming, ...$outgoing] as $edge) {
            foreach ($edge->evidence as $record) {
                $evidence[$record->id()] = ['id' => $record->id(), ...$record->toArray()];
            }
        }

        ksort($evidence, SORT_STRING);
        $processes = [];

        foreach ($snapshot->processSteps as $step) {
            if ($step->nodeId?->value === $id->value) {
                $processes[] = [
                    ...$step->toArray(),
                    'encoded_process_id' => $this->codec->encode($step->processId->value),
                ];
            }
        }

        $modules = array_values(array_map(
            fn (GraphEdge $edge): array => $this->identity($edge->target->value),
            array_filter($outgoing, static fn (GraphEdge $edge): bool => $edge->type === EdgeType::MemberOfModule),
        ));
        $effects = array_values(array_map(
            fn (GraphEdge $edge): array => $this->edge($edge, $nodes, $runtimeRelations),
            array_filter($outgoing, fn (GraphEdge $edge): bool => isset($nodes[$edge->target->value])
                && in_array($nodes[$edge->target->value]->kind, self::effectKinds(), true)),
        ));

        return [
            'data' => [
                'symbol' => $this->node($nodes[$id->value]),
                'incoming' => $this->group($incoming, $nodes, $runtimeRelations),
                'outgoing' => $this->group($outgoing, $nodes, $runtimeRelations),
                'processes' => $processes,
                'modules' => $modules,
                'effects' => $effects,
                'evidence' => array_values($evidence),
                'runtime' => $runtime === null ? null : [
                    'coverage' => $runtime['coverage'],
                    'available_session_count' => $runtime['available_session_count'],
                    'selected_session_ids' => $runtime['selected_session_ids'],
                    'relations' => array_values(array_filter(
                        $runtime['relations'],
                        static fn (array $relation): bool => $relation['source_node_id'] === $id->value
                            || $relation['target_node_id'] === $id->value,
                    )),
                ],
            ],
            'meta' => ['truncated' => $truncated],
        ];
    }

    private function group(array $edges, array $nodes, array $runtimeRelations): array
    {
        $groups = [];

        foreach ($edges as $edge) {
            $groups[$this->category($edge->type)][] = $this->edge($edge, $nodes, $runtimeRelations);
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    private function edge(GraphEdge $edge, array $nodes, array $runtimeRelations = []): array
    {
        $result = [
            'id' => $edge->id,
            'type' => $edge->type->value,
            'source' => $this->node($nodes[$edge->source->value]),
            'target' => $this->node($nodes[$edge->target->value]),
            'evidence_ids' => array_map(static fn ($record): string => $record->id(), $edge->evidence),
        ];

        $runtime = $runtimeRelations[$this->relationKey($edge)] ?? null;

        if (is_array($runtime)) {
            $result['runtime'] = [
                'observed' => $runtime['observed'],
                'runtime_only' => $runtime['runtime_only'],
                'session_count' => $runtime['session_count'],
                'observation_count' => $runtime['observation_count'],
                'coverage' => $runtime['coverage'],
                'evidence_origins' => $runtime['evidence_origins'],
                'static_certainty' => $runtime['static_certainty'],
            ];
        }

        return $result;
    }

    /** @param array<string,GraphEdge> $overlays
     *  @return list<GraphEdge>
     */
    private function relationEdges(GraphSnapshot $snapshot, NodeId $id, bool $outgoing, array $overlays): array
    {
        $staticEdges = $outgoing ? $snapshot->graph->outgoing($id) : $snapshot->graph->incoming($id);
        $edges = [];

        foreach ($staticEdges as $edge) {
            $key = $this->relationKey($edge);

            if (isset($overlays[$key])) {
                $edges['relation:'.$key] = $overlays[$key];
            } else {
                $edges['edge:'.$edge->id] = $edge;
            }
        }

        foreach ($overlays as $key => $edge) {
            $matches = $outgoing
                ? $edge->source->value === $id->value
                : $edge->target->value === $id->value;

            if ($matches) {
                $edges['relation:'.$key] = $edge;
            }
        }

        ksort($edges, SORT_STRING);

        return array_values($edges);
    }

    private function relationKey(GraphEdge $edge): string
    {
        return RuntimeEvidenceMerger::relationKey($edge->source->value, $edge->target->value, $edge->type);
    }

    private function node(GraphNode $node): array
    {
        return [...$node->toArray(), 'encoded_id' => $this->codec->encode($node->id->value)];
    }

    private function identity(string $id): array
    {
        return ['id' => $id, 'encoded_id' => $this->codec->encode($id)];
    }

    private function category(EdgeType $type): string
    {
        return match ($type) {
            EdgeType::Dispatches, EdgeType::ListensTo, EdgeType::Queues, EdgeType::Schedules => 'async',
            EdgeType::ReadsModel, EdgeType::WritesModel, EdgeType::ReadsTable, EdgeType::WritesTable,
            EdgeType::ReadsColumn, EdgeType::WritesColumn, EdgeType::ReadsCache, EdgeType::WritesCache,
            EdgeType::InvalidatesCache => 'state',
            EdgeType::ReadsConfig, EdgeType::ReadsStorage, EdgeType::WritesStorage, EdgeType::RendersView,
            EdgeType::CallsExternal, EdgeType::SendsNotification, EdgeType::SendsMail => 'external',
            EdgeType::MemberOfModule => 'module',
            EdgeType::StepInProcess, EdgeType::BranchesTo => 'process',
            EdgeType::CoveredByTest => 'test',
            EdgeType::Contains, EdgeType::Defines, EdgeType::Extends, EdgeType::Implements,
            EdgeType::UsesTrait => 'structure',
            default => 'dependency',
        };
    }

    private static function effectKinds(): array
    {
        return [
            NodeKind::Model, NodeKind::Table, NodeKind::Column, NodeKind::CacheKey,
            NodeKind::ConfigKey, NodeKind::StoragePath, NodeKind::ExternalEndpoint,
            NodeKind::View, NodeKind::Notification, NodeKind::Mailable,
        ];
    }
}
