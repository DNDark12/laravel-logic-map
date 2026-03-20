<?php

namespace dndark\LogicMap\Commands;

use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Services\SnapshotResolver;
use dndark\LogicMap\Support\ArtifactSlugger;
use dndark\LogicMap\Support\ArtifactWriter;
use dndark\LogicMap\Support\HumanLabelResolver;
use dndark\LogicMap\Support\Markdown\LlmsTxtBuilder;
use dndark\LogicMap\Support\Markdown\OverviewMarkdownBuilder;
use dndark\LogicMap\Support\Markdown\WorkflowDossierBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportDocsCommand extends Command
{
    protected $signature = 'logic-map:export-docs
                            {--snapshot= : Use a specific snapshot fingerprint instead of latest}
                            {--output= : Custom output directory}
                            {--overwrite : Overwrite existing documentation directory}
                            {--no-workflows : Only generate overview.md and llms.txt}';

    protected $description = 'Export Logic Map documentation artifacts (overview, llms.txt, workflow dossiers)';

    public function handle(SnapshotResolver $resolver, QueryLogicMapService $queryService): int
    {
        $snapshotId = $this->option('snapshot');
        $resolution = $resolver->resolve($snapshotId, true);

        if (!$resolution->hasGraph() || !$resolution->hasAnalysis()) {
            $this->error('Cannot export docs. Run logic-map:build first.');
            return self::FAILURE;
        }

        $basePath   = $this->option('output') ?: base_path('docs/logic-map');
        $writer     = new ArtifactWriter($basePath);
        $fingerprint = $resolution->resolvedFingerprint;
        $graph       = $resolution->graph;
        $analysis    = $resolution->analysis;

        // ── Guard: directory exists without --overwrite ───────────────
        if (is_dir($basePath) && !$this->option('overwrite')) {
            $this->error("Directory [{$basePath}] already exists. Use --overwrite to replace it.");
            return self::FAILURE;
        }

        // ── Selective cleanup: only managed outputs, not notes/ ───────
        if (is_dir($basePath) && $this->option('overwrite')) {
            $this->cleanManagedOutputs($basePath);
        }

        $this->info("Exporting Logic Map docs for snapshot [{$fingerprint}]...");
        $this->newLine();

        // ── Load config ───────────────────────────────────────────────
        $minSegments      = config('logic-map.doc_export.workflow_min_segments', 2);
        $maxWorkflows     = config('logic-map.doc_export.max_workflows', 50);
        $catalogThreshold = config('logic-map.doc_export.inline_catalog_max_nodes', 200);

        $allNodes = $graph->getNodes();
        $allEdges = $graph->getEdges();
        $nodeCount = count($allNodes);

        // ── Collect & filter entrypoints ──────────────────────────────
        $validRootKinds = [
            NodeKind::ROUTE,
            NodeKind::CONTROLLER,
            NodeKind::JOB,
            NodeKind::EVENT,
        ];

        $entrypointNodes = [];
        foreach ($allNodes as $node) {
            if (!in_array($node->kind, $validRootKinds)) {
                continue;
            }
            $inEdges  = $graph->getEdgesTo($node->id);
            $outEdges = $graph->getEdgesFrom($node->id);

            if ($node->kind === NodeKind::ROUTE) {
                $entrypointNodes[] = $node;
            } elseif (empty($inEdges) && !empty($outEdges)) {
                if ($node->kind === NodeKind::CONTROLLER && !str_contains($node->id, '@')) {
                    continue;
                }
                $entrypointNodes[] = $node;
            }
        }

        // Sort deterministically: id asc
        usort($entrypointNodes, fn($a, $b) => $a->id <=> $b->id);

        // ── Generate Workflow Dossiers ────────────────────────────────
        $workflowMeta   = [];
        $exportedSlugs  = [];
        $topRiskEntities = [];

        if (!$this->option('no-workflows')) {
            $this->info('Generating Workflow Dossiers...');
            $this->output->progressStart(count($entrypointNodes));

            foreach ($entrypointNodes as $node) {
                $traceResult = $queryService->trace($node->id, 'forward', 8, $fingerprint);

                if (!$traceResult->ok) {
                    $this->output->progressAdvance();
                    continue;
                }

                $data         = $traceResult->data;
                $segmentCount = $data['summary']['segment_count'] ?? 0;

                // Apply min_segments filter
                if ($segmentCount < $minSegments) {
                    $this->output->progressAdvance();
                    continue;
                }

                // Collect risk entities
                foreach ($data['review_scope']['must_review'] ?? [] as $row) {
                    $topRiskEntities[$row['node_id']] = [
                        'node_id'      => $row['node_id'],
                        'name'         => $row['name'],
                        'blast_radius' => $row['blast_radius_count'] ?? 0,
                        'risk'         => $row['risk'] ?? 'unknown',
                    ];
                }

                // Determine risk from must_review or summary
                $risk = 'low';
                if (!empty($data['review_scope']['must_review'])) {
                    $firstRisk = $data['review_scope']['must_review'][0]['risk'] ?? 'low';
                    $risk = $firstRisk;
                }

                $humanName = match ($node->kind) {
                    NodeKind::ROUTE => str_replace('route:', '', $node->id),
                    default         => $node->metadata['shortLabel'] ?? $node->name ?? $node->id,
                };

                $slug = ArtifactSlugger::slugify($node->id);

                // Handle slug collision
                $originalSlug = $slug;
                $collision    = 2;
                while (in_array($slug, $exportedSlugs)) {
                    $slug = $originalSlug . '-' . $collision++;
                }
                $exportedSlugs[] = $slug;

                $workflowMeta[] = [
                    'slug'     => $slug,
                    'title'    => $humanName,
                    'entry'    => $node->id,
                    'segments' => $segmentCount,
                    'async'    => $data['summary']['async_hops'] ?? 0,
                    'risk'     => $risk,
                ];

                $content = WorkflowDossierBuilder::build($data, $fingerprint);
                $writer->write("workflows/{$slug}.md", $content);

                $this->output->progressAdvance();

                // Apply max_workflows cap
                if (count($workflowMeta) >= $maxWorkflows) {
                    $hitWorkflowCap = true;
                    break;
                }
            }
            $this->output->progressFinish();
            if (isset($hitWorkflowCap)) {
                $this->warn("\nWorkflow cap ({$maxWorkflows}) reached — remaining entrypoints skipped.");
            }
            $this->newLine();
        }

        // Sort workflowMeta: risk desc → segments desc → slug asc
        $riskOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4, 'unknown' => 5, '' => 6];
        usort($workflowMeta, function ($a, $b) use ($riskOrder) {
            $ra = $riskOrder[strtolower($a['risk'] ?? '')] ?? 6;
            $rb = $riskOrder[strtolower($b['risk'] ?? '')] ?? 6;
            if ($ra !== $rb) return $ra <=> $rb;
            if ($a['segments'] !== $b['segments']) return $b['segments'] <=> $a['segments'];
            return strcmp($a['slug'], $b['slug']);
        });

        // ── Collect entrypoint summary for overview ───────────────────
        $entrypointSummary = [];
        foreach ($workflowMeta as $wf) {
            $entrypointSummary[] = [
                'kind'          => 'route',
                'name'          => $wf['title'],
                'node_id'       => $wf['entry'],
                'segment_count' => $wf['segments'],
                'risk'          => $wf['risk'],
            ];
        }

        // Collect analysis data for overview
        $analysisData = is_array($analysis) ? $analysis : (array)$analysis;

        // Build hotspots from analysis violations
        $hotspots = [];
        foreach ($analysisData['hotspots'] ?? [] as $hs) {
            $hotspots[] = $hs;
        }

        $graphMeta = [
            'node_count' => $nodeCount,
            'edge_count' => count($allEdges),
        ];
        $analysisMeta = [
            'health_score' => $analysisData['health_score'] ?? 100,
            'violations'   => count($analysisData['violations'] ?? []),
            'hotspots'     => $hotspots,
        ];

        // ── overview.md ───────────────────────────────────────────────
        $this->info('Writing overview.md...');
        $overviewContent = OverviewMarkdownBuilder::build(
            $graphMeta,
            $analysisMeta,
            $entrypointSummary,
            $workflowMeta,
            $fingerprint
        );
        $writer->write('overview.md', $overviewContent);

        // ── llms.txt ──────────────────────────────────────────────────
        $this->info('Writing llms.txt...');
        $topRiskList = array_values($topRiskEntities);
        $llmsContent = LlmsTxtBuilder::build(
            $allNodes,
            $allEdges,
            $workflowMeta,
            $topRiskList,
            $fingerprint,
            $catalogThreshold
        );
        $writer->write('llms.txt', $llmsContent);

        // ── nodes.md (optional, threshold-triggered) ──────────────────
        $nodesFilePath = null;
        if ($nodeCount > $catalogThreshold) {
            $this->info("Generating nodes.md ({$nodeCount} nodes > threshold {$catalogThreshold})...");
            $nodesContent = $this->buildNodesMd($allNodes, $fingerprint);
            $writer->write('nodes.md', $nodesContent);
            $nodesFilePath = 'nodes.md';
        }

        $this->newLine();
        $this->info("✓ Documentation exported to [{$basePath}]");
        $this->line("  · overview.md");
        $this->line("  · llms.txt");
        $this->line("  · workflows/ (" . count($workflowMeta) . " dossiers)");
        if ($nodesFilePath) {
            $this->line("  · nodes.md");
        }

        return self::SUCCESS;
    }

    /**
     * Remove only managed output files/dirs. Never touches notes/.
     */
    private function cleanManagedOutputs(string $basePath): void
    {
        $managed = ['overview.md', 'llms.txt', 'nodes.md', 'workflows'];
        foreach ($managed as $item) {
            $path = $basePath . DIRECTORY_SEPARATOR . $item;
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            } elseif (File::exists($path)) {
                File::delete($path);
            }
        }
        // Also remove old-format artifacts if they exist
        $legacy = ['index.md', 'glossary.md'];
        foreach ($legacy as $item) {
            $path = $basePath . DIRECTORY_SEPARATOR . $item;
            if (File::exists($path)) {
                File::delete($path);
            }
        }
    }

    /**
     * Build nodes.md catalog — only when node_count > threshold.
     */
    private function buildNodesMd(array $allNodes, string $fingerprint): string
    {
        $lines = [];
        $nodeCount = count($allNodes);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $lines[] = '# Node Catalog';
        $lines[] = '';
        $lines[] = "> Snapshot: `{$fingerprint}` | Generated: {$generatedAt} | Total: {$nodeCount} nodes";
        $lines[] = '';

        // Group by kind, sort by kind asc then id asc (deterministic)
        $byKind = [];
        foreach ($allNodes as $node) {
            $k = $node->kind->value ?? 'unknown';
            $byKind[$k][] = $node;
        }
        ksort($byKind);

        foreach ($byKind as $kind => $nodes) {
            usort($nodes, fn($a, $b) => strcmp($a->id, $b->id));
            $lines[] = '## ' . ucfirst($kind) . 's';
            $lines[] = '| ID | Name | Module |';
            $lines[] = '|----|------|--------|';
            foreach ($nodes as $n) {
                $name   = $n->metadata['shortLabel'] ?? $n->name ?? $n->id;
                $module = $n->metadata['module'] ?? '—';
                $lines[] = "| `{$n->id}` | {$name} | {$module} |";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
