<?php

namespace DNDark\LogicMap\Repositories\Database;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\GraphReader;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Support\SymbolSearchTerms;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Lazy, database-backed GraphReader. Loads only the nodes, edges, and
 * evidence a query actually touches, so request memory stays proportional
 * to the response instead of the snapshot. All lookups are memoized for the
 * lifetime of the reader (one request / one command invocation).
 */
final class DatabaseGraph implements GraphReader
{
    use HydratesGraphRows;

    private const CHUNK = 500;

    /** @var array<string, GraphNode|null> */
    private array $nodeCache = [];

    /** @var array<string, GraphEdge> */
    private array $edgeCache = [];

    /** @var array<string, \DNDark\LogicMap\Domain\Graph\EvidenceRecord> */
    private array $evidenceCache = [];

    /** @var array<string, list<string>> memo key => edge ids */
    private array $adjacencyCache = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $snapshotId,
    ) {
    }

    public function hasNode(NodeId $id): bool
    {
        return $this->findNode($id) !== null;
    }

    public function findNode(NodeId $id): ?GraphNode
    {
        if (! array_key_exists($id->value, $this->nodeCache)) {
            $row = $this->table('lm_nodes')->where('node_id', $id->value)->first();
            $this->nodeCache[$id->value] = $row === null ? null : $this->hydrateNode($row);
        }

        return $this->nodeCache[$id->value];
    }

    public function nodesByIds(array $ids): array
    {
        $wanted = [];

        foreach ($ids as $id) {
            $wanted[$id instanceof NodeId ? $id->value : $id] = true;
        }

        $missing = array_values(array_filter(
            array_keys($wanted),
            fn (string $value): bool => ! array_key_exists($value, $this->nodeCache),
        ));

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($chunk as $value) {
                $this->nodeCache[$value] = null;
            }

            foreach ($this->table('lm_nodes')->whereIn('node_id', $chunk)->get() as $row) {
                $this->nodeCache[$row->node_id] = $this->hydrateNode($row);
            }
        }

        $found = [];

        foreach (array_keys($wanted) as $value) {
            if ($this->nodeCache[$value] !== null) {
                $found[$value] = $this->nodeCache[$value];
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    public function nodesByKind(NodeKind $kind): array
    {
        return $this->rememberNodes(
            $this->table('lm_nodes')->where('kind', $kind->value)->orderBy('node_id')->get()->all(),
        );
    }

    public function nodesByQualifiedName(string $qualifiedName): array
    {
        return $this->rememberNodes(
            $this->table('lm_nodes')->where('qualified_name', $qualifiedName)->orderBy('node_id')->get()->all(),
        );
    }

    public function locatedNodes(): array
    {
        $query = $this->table('lm_nodes')
            ->whereNotNull('file')
            ->where(function ($query): void {
                foreach (['class:', 'interface:', 'trait:', 'enum:', 'method:'] as $prefix) {
                    $query->orWhere('node_id', 'like', $prefix.'%');
                }
            })
            ->orderBy('node_id');

        return $this->rememberNodes($query->get()->all());
    }

    public function searchNodes(string $term, int $limit): array
    {
        $terms = new SymbolSearchTerms($term);

        if ($terms->needle === '') {
            return [];
        }

        $rows = $this->searchQuery($terms)
            ->orderByRaw($this->searchRankExpression(), $this->searchRankBindings($terms))
            ->orderBy('node_id')
            ->limit(max(1, $limit))
            ->get()
            ->all();
        $nodes = $this->rememberNodes($rows);

        // The SQL predicate is a per-field approximation; re-check with the
        // shared cross-field semantics so both readers agree.
        return array_values(array_filter(
            $nodes,
            static fn (GraphNode $node): bool => $terms->matches([
                strtolower($node->id->value),
                strtolower($node->qualifiedName ?? ''),
                strtolower($node->name),
            ]),
        ));
    }

    public function countSearchNodes(string $term): int
    {
        $terms = new SymbolSearchTerms($term);

        if ($terms->needle === '') {
            return 0;
        }

        return $this->searchQuery($terms)->count();
    }

    public function outgoing(NodeId $id, ?array $types = null): array
    {
        return $this->adjacent($id, $types, true);
    }

    public function incoming(NodeId $id, ?array $types = null): array
    {
        return $this->adjacent($id, $types, false);
    }

    public function edgesBetween(NodeId $source, NodeId $target, ?EdgeType $type = null): array
    {
        $query = $this->table('lm_edges')
            ->where('source_id', $source->value)
            ->where('target_id', $target->value);

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $this->hydrateEdgeRows($query->orderBy('edge_id')->get()->all());
    }

    public function edgesTouching(array $nodeIds, ?array $types = null, ?array $excludeTypes = null): array
    {
        $values = [];

        foreach ($nodeIds as $id) {
            $values[$id instanceof NodeId ? $id->value : $id] = true;
        }

        $values = array_keys($values);
        $edges = [];

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $query = $this->table('lm_edges')->where(function ($query) use ($chunk): void {
                $query->whereIn('source_id', $chunk)->orWhereIn('target_id', $chunk);
            });

            if ($types !== null) {
                $query->whereIn('type', array_map(static fn (EdgeType $type): string => $type->value, $types));
            }

            if ($excludeTypes !== null) {
                $query->whereNotIn('type', array_map(static fn (EdgeType $type): string => $type->value, $excludeTypes));
            }

            foreach ($this->hydrateEdgeRows($query->orderBy('edge_id')->get()->all()) as $edge) {
                $edges[$edge->id] = $edge;
            }
        }

        ksort($edges, SORT_STRING);

        return array_values($edges);
    }

    public function membershipsOf(array $nodeIds): array
    {
        $values = [];

        foreach ($nodeIds as $id) {
            $values[$id instanceof NodeId ? $id->value : $id] = true;
        }

        $memberships = [];

        foreach (array_chunk(array_keys($values), self::CHUNK) as $chunk) {
            $rows = $this->table('lm_edges')
                ->where('type', EdgeType::MemberOfModule->value)
                ->whereIn('source_id', $chunk)
                ->orderBy('edge_id')
                ->get(['source_id', 'target_id']);

            foreach ($rows as $row) {
                $memberships[$row->source_id] = $row->target_id;
            }
        }

        return $memberships;
    }

    public function moduleMemberCounts(): array
    {
        $rows = $this->table('lm_edges')
            ->where('type', EdgeType::MemberOfModule->value)
            ->groupBy('target_id')
            ->orderBy('target_id')
            ->selectRaw('target_id, COUNT(*) as member_count')
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->target_id] = (int) $row->member_count;
        }

        return $counts;
    }

    public function evidenceByIds(array $ids): array
    {
        $missing = array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => ! isset($this->evidenceCache[$id]),
        )));

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($this->table('lm_evidence')->whereIn('evidence_id', $chunk)->get() as $row) {
                $this->evidenceCache[$row->evidence_id] = $this->hydrateEvidence($row);
            }
        }

        $found = [];

        foreach ($ids as $id) {
            if (isset($this->evidenceCache[$id])) {
                $found[$id] = $this->evidenceCache[$id];
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    public function countNodes(): int
    {
        return $this->table('lm_nodes')->count();
    }

    public function countEdges(): int
    {
        return $this->table('lm_edges')->count();
    }

    public function countEvidence(): int
    {
        return $this->table('lm_evidence')->count();
    }

    public function nodes(): array
    {
        return $this->rememberNodes($this->table('lm_nodes')->orderBy('node_id')->get()->all());
    }

    public function edges(): array
    {
        return $this->hydrateEdgeRows($this->table('lm_edges')->orderBy('edge_id')->get()->all());
    }

    public function evidence(): array
    {
        $records = [];

        foreach ($this->table('lm_evidence')->orderBy('evidence_id')->get() as $row) {
            $this->evidenceCache[$row->evidence_id] ??= $this->hydrateEvidence($row);
            $records[] = $this->evidenceCache[$row->evidence_id];
        }

        return $records;
    }

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge>
     */
    private function adjacent(NodeId $id, ?array $types, bool $outgoing): array
    {
        if ($types !== null) {
            foreach ($types as $type) {
                if (! $type instanceof EdgeType) {
                    throw new InvalidArgumentException('Edge type filters must contain EdgeType values.');
                }
            }
        }

        if (! $this->hasNode($id)) {
            throw new InvalidArgumentException("Cannot query missing node {$id->value}.");
        }

        $typeKey = $types === null
            ? '*'
            : implode(',', array_map(static fn (EdgeType $type): string => $type->value, $types));
        $memoKey = ($outgoing ? 'out' : 'in')."\0".$id->value."\0".$typeKey;

        if (! isset($this->adjacencyCache[$memoKey])) {
            $query = $this->table('lm_edges')->where($outgoing ? 'source_id' : 'target_id', $id->value);

            if ($types !== null) {
                $query->whereIn('type', array_map(static fn (EdgeType $type): string => $type->value, $types));
            }

            $edges = $this->hydrateEdgeRows($query->orderBy('edge_id')->get()->all());
            $this->adjacencyCache[$memoKey] = array_map(static fn (GraphEdge $edge): string => $edge->id, $edges);

            return $edges;
        }

        return array_values(array_map(
            fn (string $edgeId): GraphEdge => $this->edgeCache[$edgeId],
            $this->adjacencyCache[$memoKey],
        ));
    }

    /** @param list<object> $rows
     *  @return list<GraphEdge>
     */
    private function hydrateEdgeRows(array $rows): array
    {
        $pending = array_values(array_filter($rows, fn (object $row): bool => ! isset($this->edgeCache[$row->edge_id])));

        if ($pending !== []) {
            $links = [];
            $evidenceIds = [];

            foreach (array_chunk(array_column($pending, 'edge_id'), self::CHUNK) as $chunk) {
                $linkRows = $this->table('lm_edge_evidence')
                    ->whereIn('edge_id', $chunk)
                    ->orderBy('edge_id')
                    ->orderBy('evidence_id')
                    ->get(['edge_id', 'evidence_id']);

                foreach ($linkRows as $link) {
                    $links[$link->edge_id][] = $link->evidence_id;
                    $evidenceIds[$link->evidence_id] = true;
                }
            }

            $records = $this->evidenceByIds(array_keys($evidenceIds));

            foreach ($pending as $row) {
                $evidence = [];

                foreach ($links[$row->edge_id] ?? [] as $evidenceId) {
                    if (! isset($records[$evidenceId])) {
                        throw new \RuntimeException(
                            "Snapshot {$this->snapshotId} references missing evidence {$evidenceId}.",
                        );
                    }

                    $evidence[] = $records[$evidenceId];
                }

                $this->edgeCache[$row->edge_id] = $this->hydrateEdge($row, $evidence);
            }
        }

        return array_values(array_map(
            fn (object $row): GraphEdge => $this->edgeCache[$row->edge_id],
            $rows,
        ));
    }

    /** @param list<object> $rows
     *  @return list<GraphNode>
     */
    private function rememberNodes(array $rows): array
    {
        $nodes = [];

        foreach ($rows as $row) {
            $this->nodeCache[$row->node_id] ??= $this->hydrateNode($row);
            $nodes[] = $this->nodeCache[$row->node_id];
        }

        return $nodes;
    }

    private function searchQuery(SymbolSearchTerms $terms): \Illuminate\Database\Query\Builder
    {
        return $this->table('lm_nodes')->where(function ($query) use ($terms): void {
            $needle = '%'.$this->escapeLike($terms->needle).'%';
            $query->orWhere(function ($query) use ($needle): void {
                foreach (['node_id', 'qualified_name', 'name'] as $field) {
                    $query->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$needle]);
                }
            });

            if ($terms->tokens !== [] && $terms->tokens !== [$terms->needle]) {
                $query->orWhere(function ($query) use ($terms): void {
                    foreach ($terms->tokens as $token) {
                        $like = '%'.$this->escapeLike($token).'%';
                        $query->where(function ($query) use ($like): void {
                            foreach (['node_id', 'qualified_name', 'name'] as $field) {
                                $query->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$like]);
                            }
                        });
                    }
                });
            }
        });
    }

    private function searchRankExpression(): string
    {
        // The explicit ESCAPE keeps LIKE wildcard escaping portable across
        // SQLite, MySQL, and Postgres (they disagree on the default).
        return <<<'SQL'
CASE
    WHEN LOWER(node_id) = ? THEN 0
    WHEN LOWER(qualified_name) = ? THEN 1
    WHEN LOWER(node_id) LIKE ? ESCAPE '!'
        OR LOWER(qualified_name) LIKE ? ESCAPE '!'
        OR LOWER(name) LIKE ? ESCAPE '!' THEN 2
    ELSE 3
END
SQL;
    }

    /** @return list<string> */
    private function searchRankBindings(SymbolSearchTerms $terms): array
    {
        $prefix = $this->escapeLike($terms->needle).'%';

        return [$terms->needle, $terms->needle, $prefix, $prefix, $prefix];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function table(string $table): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table($table)->where('snapshot_id', $this->snapshotId);
    }
}
