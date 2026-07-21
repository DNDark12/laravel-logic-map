<?php

namespace DNDark\LogicMap\Services\Query;

use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use InvalidArgumentException;

final readonly class RuntimeEvidenceMerger
{
    public function __construct(private RuntimeEvidenceRepository $repository)
    {
    }

    /**
     * Runtime evidence is a query-time overlay. It never mutates the immutable snapshot graph.
     *
     * @param null|list<string> $selectedSessionIds Null selects all sessions for this snapshot.
     * @param null|list<string> $scopeNodeIds When given, static relations are limited to
     *        edges touching these nodes instead of the whole graph (query-path memory bound).
     * @return array{
     *     coverage:string,
     *     available_session_count:int,
     *     selected_session_ids:list<string>,
     *     relations:list<array<string,mixed>>,
     *     overlays:array<string,GraphEdge>,
     *     evidence:list<EvidenceRecord>
     * }
     */
    public function merge(GraphSnapshot $snapshot, ?array $selectedSessionIds = null, ?array $scopeNodeIds = null): array
    {
        $available = $this->sessionsForSnapshot($snapshot);
        $selected = $this->selectSessions($available, $selectedSessionIds);
        $observations = $this->relationObservations($snapshot, $selected);

        // No runtime observations means no overlays and nothing to annotate, so
        // skip materializing static relations entirely. Runtime collection is
        // opt-in and off by default, so this is the common (and cheap) path.
        if ($observations === [] && $scopeNodeIds === null) {
            $selectedIds = array_keys($selected);
            sort($selectedIds, SORT_STRING);

            return [
                'coverage' => $selected === []
                    ? 'No runtime data available'
                    : 'Observed in '.count($selected).' selected runtime sessions',
                'available_session_count' => count($available),
                'selected_session_ids' => $selectedIds,
                'relations' => [],
                'overlays' => [],
                'evidence' => [],
            ];
        }

        $static = $this->staticRelations($snapshot, $scopeNodeIds);

        // Resolve static edges for observed relations individually so the
        // whole edge set is never enumerated, and runtime_only stays accurate
        // for observations outside the requested scope.
        foreach (array_keys($observations) as $observedKey) {
            if (isset($static[$observedKey])) {
                continue;
            }

            $edges = $this->staticEdgesForKey($snapshot, $observedKey);

            if ($edges !== []) {
                $static[$observedKey] = $edges;
            }
        }

        ksort($static, SORT_STRING);
        $keys = array_values(array_unique([...array_keys($static), ...array_keys($observations)]));
        sort($keys, SORT_STRING);
        $relations = [];
        $overlays = [];
        $runtimeEvidence = [];

        foreach ($keys as $key) {
            $staticEdges = $static[$key] ?? [];
            $runtimeRows = $observations[$key] ?? [];
            $edge = $staticEdges[0] ?? null;
            $observation = $runtimeRows[0] ?? null;

            if (! $edge instanceof GraphEdge && ! $observation instanceof RuntimeObservation) {
                continue;
            }

            $source = $edge?->source->value ?? $observation->sourceNodeId;
            $target = $edge?->target->value ?? $observation->targetNodeId;
            $type = $edge?->type ?? EdgeType::from($observation->kind);
            $coverage = $this->relationCoverage($selected, $runtimeRows);
            $evidence = [];

            foreach ($staticEdges as $staticEdge) {
                foreach ($staticEdge->evidence as $record) {
                    $evidence[$record->id()] = $record;
                }
            }

            if ($runtimeRows !== []) {
                $record = $this->runtimeEvidence(
                    $snapshot->id,
                    (string) $source,
                    (string) $target,
                    $type,
                    $runtimeRows,
                    $coverage,
                );
                $evidence[$record->id()] = $record;
                $runtimeEvidence[$record->id()] = $record;
            }

            ksort($evidence, SORT_STRING);
            $evidence = array_values($evidence);
            $overlay = $edge;

            if ($runtimeRows !== []) {
                $overlay = $edge instanceof GraphEdge
                    ? new GraphEdge($edge->id, $edge->source, $edge->target, $edge->type, $edge->siteKey, $evidence)
                    : GraphEdge::fromEvidence(
                        NodeId::fromString((string) $source),
                        NodeId::fromString((string) $target),
                        $type,
                        $evidence[0],
                    );
                $overlays[$key] = $overlay;
            }
            $origins = array_values(array_unique(array_map(
                static fn (EvidenceRecord $record): string => $record->origin->value,
                $evidence,
            )));
            sort($origins, SORT_STRING);
            $sessionIds = array_values(array_unique(array_map(
                static fn (RuntimeObservation $row): string => $row->sessionId,
                $runtimeRows,
            )));
            sort($sessionIds, SORT_STRING);

            $relations[] = [
                'relation_key' => $key,
                'edge_id' => $overlay?->id,
                'source_node_id' => (string) $source,
                'target_node_id' => (string) $target,
                'type' => $type->value,
                'evidence_ids' => array_map(static fn (EvidenceRecord $record): string => $record->id(), $evidence),
                'evidence_origins' => $origins,
                'static_certainty' => $this->staticCertainty($staticEdges),
                'observed' => $runtimeRows !== [],
                'runtime_only' => $staticEdges === [],
                'session_count' => count($sessionIds),
                'observation_count' => count($runtimeRows),
                'coverage' => $coverage,
            ];
        }

        ksort($overlays, SORT_STRING);
        ksort($runtimeEvidence, SORT_STRING);
        $selectedIds = array_keys($selected);
        sort($selectedIds, SORT_STRING);

        return [
            'coverage' => $selected === []
                ? 'No runtime data available'
                : 'Observed in '.count($selected).' selected runtime sessions',
            'available_session_count' => count($available),
            'selected_session_ids' => $selectedIds,
            'relations' => $relations,
            'overlays' => $overlays,
            'evidence' => array_values($runtimeEvidence),
        ];
    }

    public static function relationKey(string $source, string $target, EdgeType $type): string
    {
        return implode("\0", [$source, $target, $type->value]);
    }

    /** @return array<string,RuntimeSession> */
    private function sessionsForSnapshot(GraphSnapshot $snapshot): array
    {
        $sessions = [];

        foreach ($this->repository->sessionsForSnapshot($snapshot->id) as $session) {
            if ($session instanceof RuntimeSession && hash_equals($snapshot->id, $session->snapshotId)) {
                $sessions[$session->id] = $session;
            }
        }

        ksort($sessions, SORT_STRING);

        return $sessions;
    }

    /** @param array<string,RuntimeSession> $available
     *  @return array<string,RuntimeSession>
     */
    private function selectSessions(array $available, ?array $selectedSessionIds): array
    {
        if ($selectedSessionIds === null) {
            return $available;
        }

        $requested = [];

        foreach ($selectedSessionIds as $id) {
            if (is_string($id) && trim($id) !== '') {
                $requested[trim($id)] = true;
            }
        }

        return array_intersect_key($available, $requested);
    }

    /** @param array<string,RuntimeSession> $selected
     *  @return array<string,list<RuntimeObservation>>
     */
    private function relationObservations(GraphSnapshot $snapshot, array $selected): array
    {
        $relations = [];

        foreach ($selected as $session) {
            foreach ($this->repository->observationsForSnapshot($snapshot->id, $session->id) as $observation) {
                if (! $observation instanceof RuntimeObservation
                    || $observation->sessionId !== $session->id
                    || ! is_string($observation->sourceNodeId)
                    || ! is_string($observation->targetNodeId)) {
                    continue;
                }

                $type = EdgeType::tryFrom($observation->kind);

                if ($type === null || ! $this->stableRelation($snapshot, $observation)) {
                    continue;
                }

                $key = self::relationKey($observation->sourceNodeId, $observation->targetNodeId, $type);
                $relations[$key][] = $observation;
            }
        }

        foreach ($relations as &$rows) {
            usort($rows, static fn (RuntimeObservation $left, RuntimeObservation $right): int => [
                $left->observedAt->format('U.u'),
                $left->sessionId,
                $left->correlationId,
            ] <=> [
                $right->observedAt->format('U.u'),
                $right->sessionId,
                $right->correlationId,
            ]);
        }
        unset($rows);
        ksort($relations, SORT_STRING);

        return $relations;
    }

    private function stableRelation(GraphSnapshot $snapshot, RuntimeObservation $observation): bool
    {
        try {
            $source = NodeId::fromString((string) $observation->sourceNodeId);
            $target = NodeId::fromString((string) $observation->targetNodeId);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $snapshot->graph->hasNode($source) && $snapshot->graph->hasNode($target);
    }

    /**
     * Static relations touching the scope nodes. A null scope yields nothing:
     * unscoped callers only need static edges for observed relation keys,
     * which merge() resolves individually — never the whole edge set.
     *
     * @param null|list<string> $scopeNodeIds
     * @return array<string,list<GraphEdge>>
     */
    private function staticRelations(GraphSnapshot $snapshot, ?array $scopeNodeIds = null): array
    {
        if ($scopeNodeIds === null) {
            return [];
        }

        $relations = [];

        foreach ($snapshot->graph->edgesTouching($scopeNodeIds) as $edge) {
            $relations[self::relationKey($edge->source->value, $edge->target->value, $edge->type)][] = $edge;
        }

        foreach ($relations as &$edges) {
            usort($edges, static fn (GraphEdge $left, GraphEdge $right): int => $left->id <=> $right->id);
        }
        unset($edges);
        ksort($relations, SORT_STRING);

        return $relations;
    }

    /** @return list<GraphEdge> */
    private function staticEdgesForKey(GraphSnapshot $snapshot, string $relationKey): array
    {
        [$source, $target, $type] = explode("\0", $relationKey, 3);
        $edgeType = EdgeType::tryFrom($type);

        if ($edgeType === null) {
            return [];
        }

        try {
            $edges = $snapshot->graph->edgesBetween(
                NodeId::fromString($source),
                NodeId::fromString($target),
                $edgeType,
            );
        } catch (InvalidArgumentException) {
            return [];
        }

        usort($edges, static fn (GraphEdge $left, GraphEdge $right): int => $left->id <=> $right->id);

        return $edges;
    }

    /** @param list<RuntimeObservation> $observations */
    private function relationCoverage(array $selected, array $observations): string
    {
        if ($selected === []) {
            return 'No runtime data available';
        }

        if ($observations === []) {
            return 'Not observed in selected sessions';
        }

        $sessions = array_unique(array_map(
            static fn (RuntimeObservation $row): string => $row->sessionId,
            $observations,
        ));

        return 'Observed in '.count($sessions).' selected runtime sessions';
    }

    /** @param list<RuntimeObservation> $observations */
    private function runtimeEvidence(
        string $snapshotId,
        string $source,
        string $target,
        EdgeType $type,
        array $observations,
        string $coverage,
    ): EvidenceRecord {
        $sessionIds = array_values(array_unique(array_map(
            static fn (RuntimeObservation $row): string => $row->sessionId,
            $observations,
        )));
        sort($sessionIds, SORT_STRING);

        return new EvidenceRecord(
            EvidenceOrigin::Runtime,
            'runtime-observation-overlay',
            Certainty::Certain,
            null,
            $source.' '.$type->value.' '.$target,
            null,
            [
                'occurrence' => hash('sha256', self::relationKey($source, $target, $type)."\0".$snapshotId),
                'snapshot_id' => $snapshotId,
                'session_ids' => $sessionIds,
                'observation_count' => count($observations),
                'coverage' => $coverage,
            ],
        );
    }

    /** @param list<GraphEdge> $edges */
    private function staticCertainty(array $edges): ?string
    {
        $rank = [
            Certainty::Possible->value => 1,
            Certainty::Probable->value => 2,
            Certainty::Certain->value => 3,
        ];
        $selected = null;
        $selectedRank = 0;

        foreach ($edges as $edge) {
            foreach ($edge->evidence as $record) {
                if ($record->origin === EvidenceOrigin::Runtime) {
                    continue;
                }

                if ($rank[$record->certainty->value] > $selectedRank) {
                    $selected = $record->certainty->value;
                    $selectedRank = $rank[$record->certainty->value];
                }
            }
        }

        return $selected;
    }
}
