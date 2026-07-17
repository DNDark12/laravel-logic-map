<?php

namespace DNDark\LogicMap\Services\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Repositories\Sqlite\SqliteSchema;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Support\CanonicalJson;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Support\SourceFingerprint;
use RuntimeException;

final readonly class IndexLogicMapService
{
    public function __construct(
        private SemanticGraphRepository $repository,
        private RepositoryFileDiscovery $discovery,
        private SourceFingerprint $fingerprint,
        private PipelineRunner $pipeline,
    ) {
    }

    public function index(IndexOptions $options): IndexResult
    {
        $sources = [];
        $indexedFiles = [];

        foreach ($this->discovery->discover($options) as $path) {
            $source = file_get_contents($this->discovery->absolute($path));

            if (! is_string($source)) {
                throw new RuntimeException("Unable to read indexed source {$path}.");
            }

            $sources[$path] = $source;
            $indexedFiles[] = new IndexedFile($path, hash('sha256', $source), strlen($source));
        }

        $fingerprint = $this->fingerprint->calculate($options, $indexedFiles);
        $active = $this->repository->active();

        if (! $options->force && $active?->sourceFingerprint === $fingerprint) {
            return new IndexResult($active, true);
        }

        $graph = new KnowledgeGraph();
        $results = $this->pipeline->run(new PipelineContext($graph, [
            'sources' => $sources,
            'boot_laravel' => $options->bootLaravel,
        ]));
        $parseDiagnostics = $results['parse_php']->diagnostics ?? [];
        $parseErrors = array_values(array_filter(
            $parseDiagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::ParseError,
        ));

        if ($parseErrors !== []) {
            throw new RuntimeException('PHP parse failed for '.count($parseErrors).' file(s).');
        }

        $diagnostics = [];
        $phaseMetrics = [];

        foreach ($results as $name => $result) {
            $phaseMetrics[$name] = $result->metrics;
            $diagnostics = [...$diagnostics, ...$result->diagnostics];
        }

        $processSteps = $results['build_process_membership']->value ?? [];

        if (! is_array($processSteps)) {
            throw new RuntimeException('Process membership phase must return process step records.');
        }

        $id = hash('sha256', SqliteSchema::VERSION."\0".$fingerprint);
        $snapshot = new GraphSnapshot(
            $id,
            SqliteSchema::VERSION,
            AnalysisVersion::CURRENT,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $fingerprint,
            $indexedFiles,
            $graph,
            $diagnostics,
            $phaseMetrics,
            $processSteps,
        );
        $existing = $this->repository->find($snapshot->id);

        if ($existing !== null) {
            if (! hash_equals($this->stableHash($existing), $this->stableHash($snapshot))) {
                throw new RuntimeException(
                    "Determinism violation for existing snapshot {$snapshot->id}; active snapshot was preserved.",
                );
            }

            $this->repository->activate($existing->id);

            return new IndexResult($existing, true);
        }

        $this->repository->store($snapshot);
        $this->repository->activate($snapshot->id);

        return new IndexResult($snapshot, false);
    }

    private function stableHash(GraphSnapshot $snapshot): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_version' => $snapshot->schemaVersion,
            'analysis_version' => $snapshot->analysisVersion,
            'fingerprint' => $snapshot->sourceFingerprint,
            'files' => array_map(static fn (IndexedFile $file): array => $file->toArray(), $snapshot->files),
            'nodes' => array_map(static fn ($node): array => $node->toArray(), $snapshot->graph->nodes()),
            'edges' => array_map(static fn ($edge): array => $edge->toArray(), $snapshot->graph->edges()),
            'evidence' => array_map(static fn ($evidence): array => $evidence->toArray(), $snapshot->graph->evidence()),
            'diagnostics' => array_map(static fn ($diagnostic): array => $diagnostic->toArray(), $snapshot->diagnostics),
            'process_steps' => array_map(static fn ($step): array => $step->toArray(), $snapshot->processSteps),
        ]));
    }
}
