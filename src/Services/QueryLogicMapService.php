<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;

class QueryLogicMapService
{
    public function __construct(
        protected GraphRepository $repository,
        protected OverviewProjector $overviewProjector,
        protected SubgraphProjector $subgraphProjector,
        protected SearchProjector $searchProjector,
        protected MetaProjector $metaProjector,
        protected FileDiscovery $discovery,
        protected Fingerprint $fingerprint,
        protected ArchitectureAnalyzer $architectureAnalyzer,
    ) {
    }

    /**
     * Get overview projection.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getOverview(array $filters = []): array
    {
        $graph = $this->getCurrentGraph();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        return $this->successResponse(
            $this->overviewProjector->overview($graph, $filters)
        );
    }

    /**
     * Get subgraph for a specific node.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getSubgraph(string $id, array $filters = []): array
    {
        $graph = $this->getCurrentGraph();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $nodes = $graph->getNodes();
        if (!isset($nodes[$id])) {
            return $this->errorResponse("Node '{$id}' not found.", 404);
        }

        return $this->successResponse(
            $this->subgraphProjector->subgraph($graph, $id, $filters)
        );
    }

    /**
     * Search nodes.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function search(string $query, array $filters = []): array
    {
        $graph = $this->getCurrentGraph();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        return $this->successResponse(
            $this->searchProjector->search($graph, $query, $filters)
        );
    }

    /**
     * Get graph metadata.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getMeta(): array
    {
        $graph = $this->getCurrentGraph();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        return $this->successResponse(
            $this->metaProjector->getMeta($graph)
        );
    }

    /**
     * Get violations from cached AnalysisReport.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getViolations(array $filters = []): array
    {
        $report = $this->getCurrentAnalysisReport();

        if (!$report) {
            return $this->errorResponse('No analysis report found. Run `php artisan logic-map:build` first.');
        }

        $violations = $report->violations;

        // Filter by severity if requested
        if (!empty($filters['severity'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($v) => $v->severity === $filters['severity']
            ));
        }

        // Filter by type if requested
        if (!empty($filters['type'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($v) => $v->type === $filters['type']
            ));
        }

        return $this->successResponse([
            'violations' => array_map(fn($v) => $v->toArray(), $violations),
            'summary' => $report->summary,
        ]);
    }

    /**
     * Get health score from cached AnalysisReport.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getHealth(): array
    {
        $report = $this->getCurrentAnalysisReport();
        $graph = $this->getCurrentGraph();

        if (!$report || !$graph) {
            return $this->errorResponse('No analysis report found. Run `php artisan logic-map:build` first.');
        }

        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();

        // Calculate aggregate stats
        $fanOuts = array_map(fn($n) => $n->metrics['fan_out'] ?? 0, array_values($nodes));
        $depths = array_filter(array_map(fn($n) => $n->metrics['depth'] ?? null, array_values($nodes)));

        return $this->successResponse([
            'score' => $report->healthScore,
            'grade' => $report->grade,
            'summary' => $report->summary,
            'graph_stats' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'avg_fan_out' => count($fanOuts) > 0 ? round(array_sum($fanOuts) / count($fanOuts), 1) : 0,
                'max_depth' => !empty($depths) ? max($depths) : 0,
            ],
        ]);
    }

    /**
     * Export full analysis data as JSON — graph + analysis report combined.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function exportJson(): array
    {
        $graph = $this->getCurrentGraph();
        $report = $this->getCurrentAnalysisReport();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $nodes = [];
        foreach ($graph->getNodes() as $node) {
            $nodeData = $node->toArray();

            // Annotate with risk if available
            if ($report && isset($report->nodeRiskMap[$node->id])) {
                $nodeData['risk'] = $report->nodeRiskMap[$node->id];
            }

            $nodes[] = $nodeData;
        }

        $data = [
            'export_version' => '1.0',
            'generated_at' => now()->toIso8601String(),
            'graph' => [
                'nodes' => $nodes,
                'edges' => array_map(fn($e) => $e->toArray(), $graph->getEdges()),
            ],
        ];

        if ($report) {
            $data['analysis'] = [
                'health_score' => $report->healthScore,
                'grade' => $report->grade,
                'summary' => $report->summary,
                'violations' => array_map(fn($v) => $v->toArray(), $report->violations),
            ];
        }

        return $this->successResponse($data);
    }

    /**
     * Export analysis data as CSV rows — one row per node with metrics + risk.
     *
     * @return array{ok: bool, data: ?string, message: ?string}
     */
    public function exportCsv(): array
    {
        $graph = $this->getCurrentGraph();
        $report = $this->getCurrentAnalysisReport();

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $headers = ['id', 'kind', 'name', 'in_degree', 'out_degree', 'fan_in', 'fan_out', 'instability', 'coupling', 'depth', 'risk', 'risk_score'];

        $rows = [];
        foreach ($graph->getNodes() as $node) {
            $risk = ($report && isset($report->nodeRiskMap[$node->id]))
                ? $report->nodeRiskMap[$node->id]
                : ['risk' => 'none', 'score' => 0];

            $rows[] = [
                $node->id,
                $node->kind->value,
                $node->name,
                $node->metrics['in_degree'] ?? 0,
                $node->metrics['out_degree'] ?? 0,
                $node->metrics['fan_in'] ?? 0,
                $node->metrics['fan_out'] ?? 0,
                $node->metrics['instability'] ?? 0,
                $node->metrics['coupling'] ?? 0,
                $node->metrics['depth'] ?? '',
                $risk['risk'],
                $risk['score'],
            ];
        }

        // Build CSV string
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'ok' => true,
            'data' => $csv,
            'message' => null,
            'content_type' => 'text/csv',
            'filename' => 'logic-map-export.csv',
        ];
    }

    /**
     * Check if a snapshot exists.
     */
    public function hasSnapshot(): bool
    {
        return $this->getCurrentGraph() !== null;
    }

    protected function getCurrentGraph(): ?Graph
    {
        $scanPaths = config('logic-map.scan_paths');
        $files = $this->discovery->findFiles($scanPaths);
        $fingerprint = $this->fingerprint->generate($files);

        // Try current fingerprint first
        $graph = $this->repository->getSnapshot($fingerprint);

        // Fallback to latest if fingerprint changed
        if (!$graph) {
            $graph = $this->repository->getLatestSnapshot();
        }

        return $graph;
    }

    protected function getCurrentAnalysisReport(): ?AnalysisReport
    {
        $scanPaths = config('logic-map.scan_paths');
        $files = $this->discovery->findFiles($scanPaths);
        $fingerprint = $this->fingerprint->generate($files);

        $configHash = $this->architectureAnalyzer->getConfigHash();

        // Try exact match first (fingerprint + configHash)
        $report = $this->repository->getAnalysisReport($fingerprint, $configHash);

        // Fallback to any report for this fingerprint
        if (!$report) {
            $report = $this->repository->getAnalysisReport($fingerprint);
        }

        return $report;
    }

    protected function successResponse(array $data): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'message' => null,
        ];
    }

    protected function errorResponse(string $message, int $code = 400): array
    {
        return [
            'ok' => false,
            'data' => null,
            'message' => $message,
            'code' => $code,
        ];
    }
}
