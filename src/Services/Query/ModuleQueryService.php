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
        $counts = $snapshot->graph->moduleMemberCounts();
        $modules = [];

        foreach ($snapshot->graph->nodesByKind(NodeKind::Module) as $node) {
            $modules[] = [
                ...$this->node($node),
                'member_count' => $counts[$node->id->value] ?? 0,
            ];
        }

        $truncated = count($modules) > $this->maxNodes;

        return [
            'data' => ['modules' => array_slice($modules, 0, $this->maxNodes)],
            'meta' => ['truncated' => $truncated],
        ];
    }

    public function find(GraphSnapshot $snapshot, NodeId $id): ?array
    {
        $module = $snapshot->graph->findNode($id);

        if ($module?->kind !== NodeKind::Module) {
            return null;
        }

        // Members of this module only; ordering follows edge ids like the
        // previous whole-graph pass did.
        $memberIds = [];

        foreach ($snapshot->graph->incoming($id, [EdgeType::MemberOfModule]) as $edge) {
            $memberIds[$edge->source->value] = true;
        }

        $memberNodes = $snapshot->graph->nodesByIds(array_keys($memberIds));
        $members = [];

        foreach ($memberIds as $memberId => $unused) {
            if (isset($memberNodes[$memberId])) {
                $members[] = $this->node($memberNodes[$memberId]);
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

        // Only edges touching this module's members can classify as
        // inbound/outbound/shared — no whole-graph pass required.
        $touching = $snapshot->graph->edgesTouching(
            array_keys($memberIds),
            null,
            [EdgeType::Contains, EdgeType::Defines, EdgeType::MemberOfModule],
        );
        $otherEndpoints = [];

        foreach ($touching as $edge) {
            $otherEndpoints[$edge->source->value] = true;
            $otherEndpoints[$edge->target->value] = true;
        }

        $memberships = [
            ...$snapshot->graph->membershipsOf(array_keys($otherEndpoints)),
            ...array_fill_keys(array_keys($memberIds), $id->value),
        ];
        $sharedCandidates = [];

        foreach ($touching as $edge) {
            if (($memberships[$edge->source->value] ?? null) === $id->value) {
                $sharedCandidates[$edge->target->value] = true;
            }
        }

        $sharedNodes = $snapshot->graph->nodesByIds(array_keys($sharedCandidates));

        foreach ($touching as $edge) {
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

            if ($sourceModule === $id->value && isset($sharedNodes[$edge->target->value])
                && in_array($sharedNodes[$edge->target->value]->kind, self::resourceKinds(), true)) {
                $shared[$edge->target->value] = $this->node($sharedNodes[$edge->target->value]);
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
                'module' => $this->node($module),
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
