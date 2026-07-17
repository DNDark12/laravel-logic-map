<?php

namespace DNDark\LogicMap\Services\Query;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Support\NodeIdCodec;

final readonly class ModuleQueryService
{
    public function __construct(
        private NodeIdCodec $codec,
        private int $maxNodes,
        private int $maxEdges,
    ) {
    }

    public function all(GraphSnapshot $snapshot): array
    {
        $counts = [];

        foreach ($snapshot->graph->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $counts[$edge->target->value] = ($counts[$edge->target->value] ?? 0) + 1;
            }
        }

        $modules = [];

        foreach ($snapshot->graph->nodes() as $node) {
            if ($node->kind === NodeKind::Module) {
                $modules[] = [
                    ...$this->node($node),
                    'member_count' => $counts[$node->id->value] ?? 0,
                ];
            }
        }

        $truncated = count($modules) > $this->maxNodes;

        return [
            'data' => ['modules' => array_slice($modules, 0, $this->maxNodes)],
            'meta' => ['truncated' => $truncated],
        ];
    }

    public function find(GraphSnapshot $snapshot, NodeId $id): ?array
    {
        $nodes = [];

        foreach ($snapshot->graph->nodes() as $node) {
            $nodes[$node->id->value] = $node;
        }

        if (($nodes[$id->value] ?? null)?->kind !== NodeKind::Module) {
            return null;
        }

        $memberships = [];

        foreach ($snapshot->graph->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $memberships[$edge->source->value] = $edge->target->value;
            }
        }

        $members = [];

        foreach ($memberships as $memberId => $moduleId) {
            if ($moduleId === $id->value && isset($nodes[$memberId])) {
                $members[] = $this->node($nodes[$memberId]);
            }
        }

        $entrypoints = array_values(array_filter($members, static fn (array $node): bool => in_array(
            $node['kind'],
            ['route', 'command', 'schedule', 'job', 'event'],
            true,
        )));
        $inbound = [];
        $outbound = [];
        $shared = [];

        foreach ($snapshot->graph->edges() as $edge) {
            if (in_array($edge->type, [EdgeType::Contains, EdgeType::Defines, EdgeType::MemberOfModule], true)) {
                continue;
            }

            $sourceModule = $memberships[$edge->source->value] ?? null;
            $targetModule = $memberships[$edge->target->value] ?? null;
            $row = [
                'id' => $edge->id,
                'type' => $edge->type->value,
                'source' => $this->identity($edge->source->value),
                'target' => $this->identity($edge->target->value),
            ];

            if ($sourceModule === $id->value && $targetModule !== null && $targetModule !== $id->value) {
                $outbound[] = $row;
            } elseif ($targetModule === $id->value && $sourceModule !== null && $sourceModule !== $id->value) {
                $inbound[] = $row;
            }

            if ($sourceModule === $id->value && isset($nodes[$edge->target->value])
                && in_array($nodes[$edge->target->value]->kind, self::resourceKinds(), true)) {
                $shared[$edge->target->value] = $this->node($nodes[$edge->target->value]);
            }
        }

        $truncated = count($members) > $this->maxNodes
            || count($inbound) + count($outbound) > $this->maxEdges;
        $members = array_slice($members, 0, $this->maxNodes);
        $inbound = array_slice($inbound, 0, $this->maxEdges);
        $outbound = array_slice($outbound, 0, max(0, $this->maxEdges - count($inbound)));
        ksort($shared, SORT_STRING);

        return [
            'data' => [
                'module' => $this->node($nodes[$id->value]),
                'members' => $members,
                'entrypoints' => $entrypoints,
                'inbound' => $inbound,
                'outbound' => $outbound,
                'shared_resources' => array_values($shared),
            ],
            'meta' => ['truncated' => $truncated],
        ];
    }

    private function node(GraphNode $node): array
    {
        return [...$node->toArray(), 'encoded_id' => $this->codec->encode($node->id->value)];
    }

    private function identity(string $id): array
    {
        return ['id' => $id, 'encoded_id' => $this->codec->encode($id)];
    }

    private static function resourceKinds(): array
    {
        return [
            NodeKind::Model, NodeKind::Table, NodeKind::Column, NodeKind::CacheKey,
            NodeKind::ConfigKey, NodeKind::StoragePath, NodeKind::ExternalEndpoint,
        ];
    }
}
