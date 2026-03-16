<?php

namespace dndark\LogicMap\Commands;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Contracts\GraphRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Standalone analysis command — re-runs analysis on cached graph without rebuilding.
 * Useful when only config thresholds change (new configHash → re-analyze).
 */
class AnalyzeLogicMapCommand extends Command
{
    protected $signature = 'logic-map:analyze
                            {--show-violations : Show individual violations}';

    protected $description = 'Run architecture analysis on the cached graph snapshot';

    public function handle(
        GraphRepository $repository,
        MetricsCalculator $metricsCalculator,
        ArchitectureAnalyzer $architectureAnalyzer,
    ): int {
        $graph = $repository->getLatestSnapshot();

        if (!$graph) {
            $this->error('No graph snapshot found. Run `php artisan logic-map:build` first.');
            return self::FAILURE;
        }

        $this->info('Running architecture analysis on cached graph...');

        // Recalculate metrics (may have changed if graph was deserialized without them)
        $metricsCalculator->calculate($graph);

        // Run analysis
        $report = $architectureAnalyzer->analyze($graph);

        // Get fingerprint for cache storage
        $fingerprint = Cache::get('logic-map.latest_fingerprint', 'unknown');
        $report->metadata['graph_fingerprint'] = $fingerprint;
        $repository->putAnalysisReport($fingerprint, $report);

        // Display results
        $summary = $report->summary;
        $totalViolations = array_sum($summary);

        $this->table(
            ['Health Score', 'Grade', 'Violations', 'Critical', 'High', 'Medium', 'Low'],
            [
                [
                    $report->healthScore,
                    $report->grade,
                    $totalViolations,
                    $summary['critical'] ?? 0,
                    $summary['high'] ?? 0,
                    $summary['medium'] ?? 0,
                    $summary['low'] ?? 0,
                ]
            ]
        );

        if ($this->option('show-violations') && $totalViolations > 0) {
            $this->newLine();
            $this->info('Violations:');

            $rows = [];
            foreach ($report->violations as $v) {
                $rows[] = [$v->severity, $v->type, $v->nodeId, $v->message];
            }

            $this->table(['Severity', 'Type', 'Node', 'Message'], $rows);
        }

        $riskyNodes = count($report->nodeRiskMap);
        if ($riskyNodes > 0) {
            $this->newLine();
            $this->line("<comment>{$riskyNodes} node(s) have elevated risk.</comment> Use GET /logic-map/violations for details.");
        }

        $this->newLine();
        $this->info('Analysis complete. Report cached.');

        return self::SUCCESS;
    }
}
