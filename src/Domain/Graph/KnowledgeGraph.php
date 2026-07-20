<?php

namespace DNDark\LogicMap\Domain\Graph;

use DNDark\LogicMap\Support\CanonicalJson;
use DNDark\LogicMap\Support\SymbolSearchTerms;
use InvalidArgumentException;

final class KnowledgeGraph implements GraphReader
{
    /** @var array<string, GraphNode> */
    private array $nodes = [];

    /** @var array<string, GraphEdge> */
    private array $edges = [];

    /** @var array<string, EvidenceRecord> */
    private array $evidence = [];

    public function addNode(GraphNode $node): void
    {
        $existing = $this->nodes[$node->id->value] ?? null;

        if ($existing !== null && CanonicalJson::encode($existing->toArray()) !== CanonicalJson::encode($node->toArray())) {
            throw new InvalidArgumentException("Conflicting graph nodes share ID {$node->id->value}.");
        }

        $this->nodes[$node->id->value] = $node;
    }

    public function addEdge(GraphEdge $edge): void
    {
        if (! isset($this->nodes[$edge->source->value], $this->nodes[$edge->target->value])) {
            throw new InvalidArgumentException('Graph edge source and target nodes must exist before the edge is added.');
        }

        $existing = $this->edges[$edge->id] ?? null;

        if ($existing !== null) {
            if (
                $existing->source->value !== $edge->source->value
                || $existing->target->value !== $edge->target->value
                || $existing->type !== $edge->type
                || $existing->siteKey !== $edge->siteKey
            ) {
                throw new InvalidArgumentException("Conflicting graph edges share ID {$edge->id}.");
            }

            foreach ($edge->evidence as $record) {
                $existing->addEvidence($record);
                $this->evidence[$record->id()] = $record;
            }

            return;
        }

        $this->edges[$edge->id] = $edge;

        foreach ($edge->evidence as $record) {
            $this->evidence[$record->id()] = $record;
        }
    }

    public function hasNode(NodeId $id): bool
    {
        return isset($this->nodes[$id->value]);
    }

    public function findNode(NodeId $id): ?GraphNode
    {
        return $this->nodes[$id->value] ?? null;
    }

    public function nodesByIds(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            $value = $id instanceof NodeId ? $id->value : $id;

            if (isset($this->nodes[$value])) {
                $found[$value] = $this->nodes[$value];
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    public function nodesByKind(NodeKind $kind): array
    {
        return array_values(array_filter(
            $this->nodes(),
            static fn (GraphNode $node): bool => $node->kind === $kind,
        ));
    }

    public function nodesByQualifiedName(string $qualifiedName): array
    {
        return array_values(array_filter(
            $this->nodes(),
            static fn (GraphNode $node): bool => $node->qualifiedName === $qualifiedName,
        ));
    }

    public function locatedNodes(): array
    {
        return array_values(array_filter(
            $this->nodes(),
            static fn (GraphNode $node): bool => $node->location !== null
                && preg_match('/^(class|interface|trait|enum|method):/', $node->id->value) === 1,
        ));
    }

    public function searchNodes(string $term, int $limit): array
    {
        $terms = new SymbolSearchTerms($term);
        $matches = [];

        foreach ($this->nodes() as $node) {
            if (count($matches) >= $limit) {
                break;
            }

            if ($terms->matches(self::searchFields($node))) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    public function countSearchNodes(string $term): int
    {
        $terms = new SymbolSearchTerms($term);
        $count = 0;

        foreach ($this->nodes as $node) {
            if ($terms->matches(self::searchFields($node))) {
                $count++;
            }
        }

        return $count;
    }

    public function edgesBetween(NodeId $source, NodeId $target, ?EdgeType $type = null): array
    {
        return array_values(array_filter(
            $this->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source->value
                && $edge->target->value === $target->value
                && ($type === null || $edge->type === $type),
        ));
    }

    public function edgesTouching(array $nodeIds, ?array $types = null, ?array $excludeTypes = null): array
    {
        $ids = [];

        foreach ($nodeIds as $id) {
            $ids[$id instanceof NodeId ? $id->value : $id] = true;
        }

        return array_values(array_filter(
            $this->edges(),
            static fn (GraphEdge $edge): bool => (isset($ids[$edge->source->value]) || isset($ids[$edge->target->value]))
                && ($types === null || in_array($edge->type, $types, true))
                && ($excludeTypes === null || ! in_array($edge->type, $excludeTypes, true)),
        ));
    }

    public function membershipsOf(array $nodeIds): array
    {
        $ids = [];

        foreach ($nodeIds as $id) {
            $ids[$id instanceof NodeId ? $id->value : $id] = true;
        }

        $memberships = [];

        foreach ($this->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule && isset($ids[$edge->source->value])) {
                $memberships[$edge->source->value] = $edge->target->value;
            }
        }

        return $memberships;
    }

    public function moduleMemberCounts(): array
    {
        $counts = [];

        foreach ($this->edges as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $counts[$edge->target->value] = ($counts[$edge->target->value] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    public function evidenceByIds(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            if (isset($this->evidence[$id])) {
                $found[$id] = $this->evidence[$id];
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    public function countNodes(): int
    {
        return count($this->nodes);
    }

    public function countEdges(): int
    {
        return count($this->edges);
    }

    public function countEvidence(): int
    {
        return count($this->evidence);
    }

    /** @return list<string> */
    private static function searchFields(GraphNode $node): array
    {
        return [
            strtolower($node->id->value),
            strtolower($node->qualifiedName ?? ''),
            strtolower($node->name),
        ];
    }

    public function applyClassification(
        NodeId $id,
        NodeKind $kind,
        Certainty $certainty,
        string $reason,
    ): void {
        $node = $this->nodes[$id->value] ?? null;

        if ($node === null) {
            throw new InvalidArgumentException("Cannot classify missing node {$id->value}.");
        }

        if (! preg_match('/^(class|interface|trait|enum):/', $id->value)) {
            throw new InvalidArgumentException('Only structural class-like nodes may receive semantic classifications.');
        }

        if (! in_array($kind, self::classificationKinds(), true)) {
            throw new InvalidArgumentException("Node kind {$kind->value} is not a class-like semantic classification.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Classification reason is required.');
        }

        $currentCertainty = isset($node->attributes['classification_certainty'])
            ? Certainty::tryFrom((string) $node->attributes['classification_certainty'])
            : null;

        if ($currentCertainty !== null) {
            $currentRank = self::certaintyRank($currentCertainty);
            $nextRank = self::certaintyRank($certainty);

            if ($nextRank < $currentRank) {
                return;
            }

            if ($nextRank === $currentRank) {
                if ($node->kind !== $kind) {
                    throw new InvalidArgumentException(
                        "Conflicting equal-precedence classifications for {$id->value}.",
                    );
                }

                return;
            }
        }

        $attributes = $node->attributes;
        $attributes['classification_certainty'] = $certainty->value;
        $attributes['classification_reason'] = $reason;

        $this->nodes[$id->value] = new GraphNode(
            $node->id,
            $kind,
            $node->name,
            $node->qualifiedName,
            $node->location,
            $attributes,
        );
    }

    /** @return list<GraphNode> */
    public function nodes(): array
    {
        $nodes = $this->nodes;
        ksort($nodes, SORT_STRING);

        return array_values($nodes);
    }

    /** @return list<GraphEdge> */
    public function edges(): array
    {
        $edges = $this->edges;
        ksort($edges, SORT_STRING);

        return array_values($edges);
    }

    /** @return list<EvidenceRecord> */
    public function evidence(): array
    {
        $evidence = $this->evidence;
        ksort($evidence, SORT_STRING);

        return array_values($evidence);
    }

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge>
     */
    public function outgoing(NodeId $id, ?array $types = null): array
    {
        return $this->adjacent($id, $types, true);
    }

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge>
     */
    public function incoming(NodeId $id, ?array $types = null): array
    {
        return $this->adjacent($id, $types, false);
    }

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge>
     */
    private function adjacent(NodeId $id, ?array $types, bool $outgoing): array
    {
        if (! isset($this->nodes[$id->value])) {
            throw new InvalidArgumentException("Cannot query missing node {$id->value}.");
        }

        if ($types !== null) {
            foreach ($types as $type) {
                if (! $type instanceof EdgeType) {
                    throw new InvalidArgumentException('Edge type filters must contain EdgeType values.');
                }
            }
        }

        return array_values(array_filter(
            $this->edges(),
            static fn (GraphEdge $edge): bool => ($outgoing
                ? $edge->source->value === $id->value
                : $edge->target->value === $id->value)
                && ($types === null || in_array($edge->type, $types, true)),
        ));
    }

    /** @return list<NodeKind> */
    private static function classificationKinds(): array
    {
        return [
            NodeKind::ClassSymbol,
            NodeKind::Middleware,
            NodeKind::FormRequest,
            NodeKind::Policy,
            NodeKind::Controller,
            NodeKind::Action,
            NodeKind::Service,
            NodeKind::Repository,
            NodeKind::Command,
            NodeKind::Job,
            NodeKind::Event,
            NodeKind::Listener,
            NodeKind::Notification,
            NodeKind::Mailable,
            NodeKind::Model,
        ];
    }

    private static function certaintyRank(Certainty $certainty): int
    {
        return match ($certainty) {
            Certainty::Possible => 1,
            Certainty::Probable => 2,
            Certainty::Certain => 3,
        };
    }
}
