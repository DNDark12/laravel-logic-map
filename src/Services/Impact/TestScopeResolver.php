<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphReader;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use InvalidArgumentException;

final readonly class TestScopeResolver
{
    public function __construct(private GraphReader $graph)
    {
    }

    public function resolve(array $affectedNodeIds, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Test scope limit must be positive.');
        }

        $candidates = [];
        $modules = [];
        $affectedById = [];

        foreach ($affectedNodeIds as $affected) {
            if (! $affected instanceof NodeId) {
                throw new InvalidArgumentException('Test scope requires NodeId values.');
            }

            $affectedById[$affected->value] = $affected;
        }

        $known = $this->graph->nodesByIds(array_values($affectedById));
        $edges = $this->graph->edgesTouching(
            array_keys($known),
            [EdgeType::CoveredByTest, EdgeType::MemberOfModule],
        );

        foreach ($edges as $edge) {
            if ($edge->type === EdgeType::CoveredByTest && isset($known[$edge->target->value])) {
                $affected = $edge->target;
                $evidence = $edge->evidence;
                $runtime = array_filter($evidence, static fn ($record): bool => $record->origin === EvidenceOrigin::Runtime);
                $direct = array_filter($evidence, static fn ($record): bool => ($record->attributes['reference_kind'] ?? null) === 'direct_symbol');
                $rank = $runtime !== [] ? 1 : ($direct !== [] ? 2 : 3);
                $kind = $rank === 1 ? 'runtime method coverage' : ($rank === 2 ? 'direct static symbol reference' : 'Laravel route/event/job/table reference');
                $this->candidate(
                    $candidates,
                    $edge->source->value,
                    $rank,
                    "Selected by {$kind} to {$affected->value}.",
                    array_map(static fn ($record): string => $record->id(), $evidence),
                );

                continue;
            }

            if ($edge->type === EdgeType::MemberOfModule && isset($known[$edge->source->value])) {
                $modules[$edge->target->value] = array_map(
                    static fn ($record): string => $record->id(),
                    $edge->evidence,
                );
            }
        }

        // Only scan test nodes when at least one affected module was found;
        // otherwise there is nothing module-scoped to match.
        foreach ($modules === [] ? [] : $this->graph->nodesByKind(NodeKind::Test) as $node) {
            if (! is_string($node->attributes['module'] ?? null)) {
                continue;
            }

            $moduleId = 'module:'.$node->attributes['module'];

            if (! isset($modules[$moduleId])) {
                continue;
            }

            $this->candidate(
                $candidates,
                $node->id->value,
                4,
                "Selected by affected module test namespace {$node->attributes['module']}.",
                $modules[$moduleId],
            );
        }

        uasort($candidates, static fn (array $left, array $right): int => [
            $left['rank'],
            $left['test_node_id'],
        ] <=> [
            $right['rank'],
            $right['test_node_id'],
        ]);

        return array_slice(array_values($candidates), 0, $limit);
    }

    private function candidate(
        array &$candidates,
        string $testNodeId,
        int $rank,
        string $reason,
        array $evidenceIds,
    ): void {
        $existing = $candidates[$testNodeId] ?? null;

        if ($existing !== null && $existing['rank'] < $rank) {
            return;
        }

        if ($existing !== null && $existing['rank'] === $rank) {
            $existing['evidence_ids'] = array_values(array_unique([...$existing['evidence_ids'], ...$evidenceIds]));
            sort($existing['evidence_ids'], SORT_STRING);
            $candidates[$testNodeId] = $existing;

            return;
        }

        $evidenceIds = array_values(array_unique($evidenceIds));
        sort($evidenceIds, SORT_STRING);
        $candidates[$testNodeId] = [
            'test_node_id' => $testNodeId,
            'rank' => $rank,
            'reason' => $reason,
            'evidence_ids' => $evidenceIds,
        ];
    }
}
