<?php

namespace DNDark\LogicMap\Services\Query;

use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Support\NodeIdCodec;

final readonly class SymbolSearchService
{
    public function __construct(
        private NodeIdCodec $codec,
        private int $maxResults,
    ) {
    }

    public function search(GraphSnapshot $snapshot, string $query): array
    {
        $needle = strtolower(trim($query));
        $tokens = preg_split('/[^a-z0-9_\\:-]+/i', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ranked = [];

        foreach ($snapshot->graph->nodes() as $node) {
            $fields = [
                strtolower($node->id->value),
                strtolower($node->qualifiedName ?? ''),
                strtolower($node->name),
            ];
            $rank = $this->rank($fields, $needle, $tokens);

            if ($rank !== null) {
                $ranked[] = [$rank, $node];
            }
        }

        usort($ranked, static fn (array $left, array $right): int => [
            $left[0],
            $left[1]->id->value,
        ] <=> [
            $right[0],
            $right[1]->id->value,
        ]);
        $totalMatches = count($ranked);
        $truncated = $totalMatches > $this->maxResults;
        $ranked = array_slice($ranked, 0, $this->maxResults);
        $results = array_map(fn (array $match): array => $this->node($match[1]), $ranked);
        $exact = array_values(array_filter($ranked, static fn (array $match): bool => $match[0] <= 1));

        return [
            'data' => [
                'query' => trim($query),
                'selection' => count($exact) === 1 ? $this->node($exact[0][1]) : null,
                'results' => $results,
            ],
            'meta' => ['truncated' => $truncated, 'total_matches' => $totalMatches],
        ];
    }

    private function rank(array $fields, string $needle, array $tokens): ?int
    {
        if ($fields[0] === $needle) {
            return 0;
        }

        if ($fields[1] !== '' && $fields[1] === $needle) {
            return 1;
        }

        foreach ($fields as $field) {
            if (str_starts_with($field, $needle)) {
                return 2;
            }
        }

        $haystack = implode(' ', $fields);

        if ($tokens !== []) {
            foreach ($tokens as $token) {
                if (! str_contains($haystack, $token)) {
                    return str_contains($haystack, $needle) ? 4 : null;
                }
            }

            return 3;
        }

        return str_contains($haystack, $needle) ? 4 : null;
    }

    private function node(GraphNode $node): array
    {
        return [
            ...$node->toArray(),
            'encoded_id' => $this->codec->encode($node->id->value),
        ];
    }
}
