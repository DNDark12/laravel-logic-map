<?php

namespace dndark\LogicMap\Domain;

use dndark\LogicMap\Domain\Enums\NodeKind;

class Graph
{
    /** @var array<string, Node> */
    protected array $nodes = [];

    /** @var array<Edge> */
    protected array $edges = [];

    /** @var array<string, bool> Track added edge keys for deduplication */
    protected array $edgeKeys = [];

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        // Deduplicate edges by source+target+type
        $key = "{$edge->source}->{$edge->target}:{$edge->type->value}";
        if (!isset($this->edgeKeys[$key])) {
            $this->edges[] = $edge;
            $this->edgeKeys[$key] = true;
        }
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function getEdges(): array
    {
        return $this->edges;
    }

    /**
     * Get unique edges (deduplicated by source+target+type).
     * This is useful when edges may have been added from multiple sources.
     *
     * @return Edge[]
     */
    public function getUniqueEdges(): array
    {
        $seen = [];
        $unique = [];
        foreach ($this->edges as $edge) {
            $key = "{$edge->source}->{$edge->target}:{$edge->type->value}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $edge;
            }
        }
        return $unique;
    }

    // ─── Helper Methods (Sprint 4) ────────────────────────────

    /**
     * Get a single node by ID.
     */
    public function getNode(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Check if a node exists.
     */
    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * Get all edges originating FROM a node.
     *
     * @return Edge[]
     */
    public function getEdgesFrom(string $nodeId): array
    {
        return array_values(array_filter(
            $this->edges,
            fn(Edge $e) => $e->source === $nodeId
        ));
    }

    /**
     * Get all edges pointing TO a node.
     *
     * @return Edge[]
     */
    public function getEdgesTo(string $nodeId): array
    {
        return array_values(array_filter(
            $this->edges,
            fn(Edge $e) => $e->target === $nodeId
        ));
    }

    /**
     * Get all nodes of a specific kind.
     *
     * @return Node[]
     */
    public function getNodesByKind(NodeKind $kind): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn(Node $n) => $n->kind === $kind
        ));
    }

    /**
     * Get nodes eligible for architecture analysis.
     * These are structural code nodes where orphan/coupling analysis is meaningful.
     *
     * @return Node[]
     */
    public function getAnalyzableNodes(): array
    {
        $analyzableKinds = [
            NodeKind::CONTROLLER,
            NodeKind::SERVICE,
            NodeKind::MODEL,
            NodeKind::REPOSITORY,
            NodeKind::JOB,
        ];

        return array_values(array_filter(
            $this->nodes,
            fn(Node $n) => in_array($n->kind, $analyzableKinds, true)
        ));
    }

    // ─── Serialization ────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'nodes' => array_map(fn(Node $n) => $n->toArray(), array_values($this->nodes)),
            'edges' => array_map(fn(Edge $e) => $e->toArray(), $this->edges),
        ];
    }

    public static function fromArray(array $data): self
    {
        $graph = new self();

        foreach ($data['nodes'] ?? [] as $nodeData) {
            $graph->addNode(Node::fromArray($nodeData));
        }

        foreach ($data['edges'] ?? [] as $edgeData) {
            $graph->addEdge(Edge::fromArray($edgeData));
        }

        return $graph;
    }
}
