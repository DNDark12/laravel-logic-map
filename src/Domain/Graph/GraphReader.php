<?php

namespace DNDark\LogicMap\Domain\Graph;

/**
 * Read API over a snapshot graph.
 *
 * Two implementations exist: the in-memory KnowledgeGraph used at index time,
 * and the lazy database-backed DatabaseGraph used at query time. Query-path
 * consumers must prefer the targeted methods below over the full
 * nodes()/edges()/evidence() materializers, which load the entire graph and
 * are only appropriate for index/export flows.
 */
interface GraphReader
{
    public function hasNode(NodeId $id): bool;

    public function findNode(NodeId $id): ?GraphNode;

    /**
     * @param list<NodeId|string> $ids
     * @return array<string, GraphNode> keyed by node id
     */
    public function nodesByIds(array $ids): array;

    /** @return list<GraphNode> ordered by node id */
    public function nodesByKind(NodeKind $kind): array;

    /** @return list<GraphNode> ordered by node id */
    public function nodesByQualifiedName(string $qualifiedName): array;

    /**
     * Class-like and method nodes that carry a source location.
     *
     * @return list<GraphNode> ordered by node id
     */
    public function locatedNodes(): array;

    /**
     * Bounded candidate set for symbol search. Candidates are a superset
     * ordered approximately by match quality; callers re-rank precisely.
     *
     * @return list<GraphNode>
     */
    public function searchNodes(string $term, int $limit): array;

    /** Total node count matching the search predicate used by searchNodes(). */
    public function countSearchNodes(string $term): int;

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge> ordered by edge id
     */
    public function outgoing(NodeId $id, ?array $types = null): array;

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge> ordered by edge id
     */
    public function incoming(NodeId $id, ?array $types = null): array;

    /** @return list<GraphEdge> ordered by edge id */
    public function edgesBetween(NodeId $source, NodeId $target, ?EdgeType $type = null): array;

    /**
     * Edges whose source or target is one of the given nodes.
     *
     * @param list<NodeId|string> $nodeIds
     * @param null|list<EdgeType> $types
     * @param null|list<EdgeType> $excludeTypes
     * @return list<GraphEdge> ordered by edge id
     */
    public function edgesTouching(array $nodeIds, ?array $types = null, ?array $excludeTypes = null): array;

    /**
     * member_of_module membership for the given member node ids.
     *
     * @param list<NodeId|string> $nodeIds
     * @return array<string, string> member node id => module node id
     */
    public function membershipsOf(array $nodeIds): array;

    /** @return array<string, int> module node id => member count */
    public function moduleMemberCounts(): array;

    /**
     * @param list<string> $ids
     * @return array<string, EvidenceRecord> keyed by evidence id
     */
    public function evidenceByIds(array $ids): array;

    public function countNodes(): int;

    public function countEdges(): int;

    public function countEvidence(): int;

    /** Full materialization — index/export flows only. @return list<GraphNode> */
    public function nodes(): array;

    /** Full materialization — index/export flows only. @return list<GraphEdge> */
    public function edges(): array;

    /** Full materialization — index/export flows only. @return list<EvidenceRecord> */
    public function evidence(): array;
}
