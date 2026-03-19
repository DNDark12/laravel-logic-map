<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Analysis\AstParser;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Analysis\Runtime\CoverageMetadataCollector;
use dndark\LogicMap\Analysis\Runtime\RouteMetadataCollector;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;

class BuildLogicMapService
{
    public function __construct(
        protected FileDiscovery $discovery,
        protected Fingerprint $fingerprint,
        protected AstParser $parser,
        protected GraphRepository $repository,
        protected RouteMetadataCollector $routeMetadata,
        protected CoverageMetadataCollector $coverageMetadata,
        protected MetricsCalculator $metricsCalculator,
        protected ArchitectureAnalyzer $architectureAnalyzer,
    ) {
    }

    /**
     * Build the logic map from the project files.
     *
     * @param bool $force
     * @return array{graph: Graph, fingerprint: string, status: string, diagnostics: ?array, analysis: ?array}
     */
    public function build(bool $force = false): array
    {
        $scanPaths = config('logic-map.scan_paths', [base_path('app'), base_path('routes')]);
        $files = $this->discovery->findFiles($scanPaths);
        $currentFingerprint = $this->fingerprint->generate($files);

        if (!$force) {
            $snapshot = $this->repository->getSnapshot($currentFingerprint);
            if ($snapshot) {
                // Check if analysis also cached with current config
                $configHash = $this->architectureAnalyzer->getConfigHash();
                $report = $this->repository->getAnalysisReport($currentFingerprint, $configHash);
                if ($report !== null) {
                    $this->repository->setActiveFingerprint($currentFingerprint);
                }

                return [
                    'graph' => $snapshot,
                    'fingerprint' => $currentFingerprint,
                    'status' => 'cached',
                    'diagnostics' => null,
                    'analysis' => $report?->toArray(),
                ];
            }
        }

        $graph = $this->parser->parse($files);
        $diagnostics = $this->parser->getDiagnostics();

        // Enrich with runtime metadata
        $this->routeMetadata->collect($graph);
        $this->coverageMetadata->collect($graph);

        // Calculate structural metrics → Node.metrics (canonical)
        $this->metricsCalculator->calculate($graph);

        // Store canonical graph snapshot
        $this->repository->putSnapshot($currentFingerprint, $graph);

        // Run architecture analysis → AnalysisReport (derived, separate cache)
        $analysisReport = $this->architectureAnalyzer->analyze($graph);
        $analysisReport->metadata['graph_fingerprint'] = $currentFingerprint;
        $this->repository->putAnalysisReport($currentFingerprint, $analysisReport);
        $this->repository->setActiveFingerprint($currentFingerprint);

        return [
            'graph' => $graph,
            'fingerprint' => $currentFingerprint,
            'status' => 'rebuilt',
            'diagnostics' => $diagnostics,
            'analysis' => $analysisReport->toArray(),
        ];
    }
}
