<?php

namespace DNDark\LogicMap\Services\Query;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Support\SchemaVersion;

final readonly class LogicMapStatusService
{
    public function __construct(private SemanticGraphRepository $repository) {}

    public function status(): array
    {
        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return [
                'active' => false,
                'snapshot' => null,
                'counts' => ['nodes' => 0, 'edges' => 0, 'evidence' => 0, 'diagnostics' => 0],
                'diagnostic_coverage' => [],
            ];
        }

        $diagnostics = [];

        foreach ($snapshot->diagnostics as $diagnostic) {
            $diagnostics[$diagnostic->code->value] = ($diagnostics[$diagnostic->code->value] ?? 0) + 1;
        }

        ksort($diagnostics, SORT_STRING);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'active' => true,
            'snapshot' => [
                'id' => $snapshot->id,
                'schema_version' => $snapshot->schemaVersion,
                'analysis_version' => $snapshot->analysisVersion,
                'fingerprint' => $snapshot->sourceFingerprint,
                'indexed_at' => $snapshot->indexedAt->format(DATE_ATOM),
                'age_seconds' => max(0, $now->getTimestamp() - $snapshot->indexedAt->getTimestamp()),
                'stale' => $snapshot->schemaVersion !== SchemaVersion::VERSION
                    || $snapshot->analysisVersion !== AnalysisVersion::CURRENT,
            ],
            'counts' => [
                'nodes' => $snapshot->graph->countNodes(),
                'edges' => $snapshot->graph->countEdges(),
                'evidence' => $snapshot->graph->countEvidence(),
                'diagnostics' => count($snapshot->diagnostics),
            ],
            'diagnostic_coverage' => $diagnostics,
        ];
    }
}
