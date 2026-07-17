<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Support\CanonicalJson;

final class CanonicalGraphProjector
{
    private const VOLATILE_KEYS = [
        'indexed_at',
        'phase_metrics',
        'metrics',
        'duration',
        'duration_ns',
        'duration_ms',
        'elapsed',
        'elapsed_ns',
        'elapsed_ms',
        'memory',
        'memory_bytes',
        'peak_memory',
        'peak_memory_bytes',
        'timing',
        'timings',
    ];

    public function project(GraphSnapshot $snapshot): array
    {
        $nodes = array_map(
            fn ($node): array => $this->canonicalRecord($node->toArray()),
            $snapshot->graph->nodes(),
        );
        $edges = array_map(
            fn ($edge): array => $this->canonicalRecord($edge->toArray()),
            $snapshot->graph->edges(),
        );
        $evidence = array_map(
            fn ($record): array => $this->canonicalRecord([
                'id' => $record->id(),
                ...$record->toArray(),
            ]),
            $snapshot->graph->evidence(),
        );
        $diagnostics = array_map(
            fn ($diagnostic): array => $this->canonicalRecord($diagnostic->toArray()),
            $snapshot->diagnostics,
        );
        usort($diagnostics, static fn (array $left, array $right): int =>
            CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
        $processSteps = array_map(
            fn ($step): array => $this->canonicalRecord($step->toArray()),
            $snapshot->processSteps,
        );

        return [
            'schema_version' => $snapshot->schemaVersion,
            'analysis_version' => $snapshot->analysisVersion,
            'snapshot_id' => $snapshot->id,
            'fingerprint' => $snapshot->sourceFingerprint,
            'nodes' => $nodes,
            'edges' => $edges,
            'evidence' => $evidence,
            'diagnostics' => $diagnostics,
            'process_steps' => $processSteps,
        ];
    }

    public function json(GraphSnapshot $snapshot): string
    {
        return json_encode(
            $this->project($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalRecord(array $record): array
    {
        return $this->sanitize($record);
    }

    private function sanitize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sanitize($item) : $item,
                $value,
            );
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), self::VOLATILE_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitize($item) : $item;
        }

        ksort($sanitized, SORT_STRING);

        return $sanitized;
    }
}
