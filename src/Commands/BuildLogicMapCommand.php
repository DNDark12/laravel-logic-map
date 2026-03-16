<?php

namespace dndark\LogicMap\Commands;

use dndark\LogicMap\Services\BuildLogicMapService;
use Illuminate\Console\Command;

class BuildLogicMapCommand extends Command
{
    protected $signature = 'logic-map:build
                            {--force : Force a rebuild of the graph}
                            {--show-errors : Show parse error details}';

    protected $description = 'Scan project and build logic map snapshot';

    public function handle(BuildLogicMapService $service): int
    {
        $this->info('Scanning project files and building logic map...');

        $result = $service->build($this->option('force'));

        // Main stats table
        $this->table(
            ['Graph Status', 'Fingerprint', 'Nodes', 'Edges'],
            [
                [
                    ucfirst($result['status']),
                    $result['fingerprint'],
                    count($result['graph']->getNodes()),
                    count($result['graph']->getEdges()),
                ]
            ]
        );

        // Show diagnostics if available
        if ($result['diagnostics']) {
            $diagnostics = $result['diagnostics'];

            $this->newLine();
            $this->table(
                ['Files Scanned', 'Parsed', 'Skipped'],
                [
                    [
                        $diagnostics['total_files'],
                        $diagnostics['parsed_files'],
                        $diagnostics['skipped_files'],
                    ]
                ]
            );

            // Show errors if requested or if there are errors
            if ($this->option('show-errors') && count($diagnostics['error_files']) > 0) {
                $this->newLine();
                $this->warn('Parse Errors:');

                foreach ($diagnostics['error_files'] as $error) {
                    $line = isset($error['line']) ? " (line {$error['line']})" : '';
                    $this->line("  • {$error['file']}{$line}");
                    $this->line("    {$error['error']}");
                }
            } elseif ($diagnostics['skipped_files'] > 0) {
                $this->line("<comment>Note:</comment> {$diagnostics['skipped_files']} file(s) skipped. Use --show-errors for details.");
            }
        }

        if (!empty($result['analysis'])) {
            $analysis = $result['analysis'];
            $summary = $analysis['summary'] ?? [];
            $totalViolations = array_sum($summary);

            $this->newLine();
            $this->table(
                ['Health Score', 'Grade', 'Violations', 'Critical', 'High', 'Medium', 'Low'],
                [
                    [
                        $analysis['health_score'] ?? 100,
                        $analysis['grade'] ?? 'A',
                        $totalViolations,
                        $summary['critical'] ?? 0,
                        $summary['high'] ?? 0,
                        $summary['medium'] ?? 0,
                        $summary['low'] ?? 0,
                    ]
                ]
            );

            if ($totalViolations > 0) {
                $this->line("<comment>Run</comment> GET /logic-map/violations <comment>for details.</comment>");
            }
        }

        $this->newLine();
        $this->info('Logic map snapshot generated successfully.');
        $this->newLine();

        $baseUrl = url('logic-map');
        $this->line('<fg=cyan>  ▸ Visual Explorer:</> ' . $baseUrl);
        $this->line('<fg=cyan>  ▸ Health API:</>      ' . $baseUrl . '/health');
        $this->line('<fg=cyan>  ▸ Violations API:</>  ' . $baseUrl . '/violations');
        $this->line('<fg=cyan>  ▸ Export JSON:</>     ' . $baseUrl . '/export/json');
        $this->newLine();
        $this->line('<comment>Tip:</comment> Run <fg=green>php artisan logic-map:analyze --show-violations</> to see details in terminal.');

        return self::SUCCESS;
    }
}
