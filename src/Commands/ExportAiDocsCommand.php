<?php

namespace DNDark\LogicMap\Commands;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Projectors\GraphJsonProjector;
use DNDark\LogicMap\Projectors\ImpactWeightProjector;
use DNDark\LogicMap\Services\Impact\ImpactQueryService;
use DNDark\LogicMap\Support\SafeOutputWriter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Machine-readable AI documentation bundle: the full graph (GraphJsonProjector),
 * a weighted per-symbol impact set (ImpactWeightProjector wrapping
 * ImpactQueryService's symbol mode), and an llms.txt/index.md pair so an
 * agent can load "what does changing X affect, and how strongly" without
 * running the application or the /logic-map viewer. Orchestration only —
 * impact semantics are never reimplemented here. All timestamps shown come
 * from the snapshot itself (never wall-clock "now"), and every list is
 * sorted by canonical ID, so the bundle is byte-identical across repeated
 * exports of the same snapshot.
 */
final class ExportAiDocsCommand extends Command
{
    /** @var list<NodeKind> */
    private const CHANGEABLE_KIND_PRIORITY = [
        NodeKind::Route,
        NodeKind::Command,
        NodeKind::Job,
        NodeKind::Listener,
        NodeKind::Method,
    ];

    protected $signature = 'logic-map:export-ai
                            {--output= : Repository-relative output directory (defaults to logic-map.doc_export.ai.output)}
                            {--force : Overwrite existing files}
                            {--symbols=* : Canonical node IDs or qualified names to export impact for (overrides automatic enumeration and the max_impact_symbols bound)}';

    protected $description = 'Export the active Laravel Logic Map V2 snapshot as a machine-readable, impact-weighted AI documentation bundle';

    public function handle(SemanticGraphRepository $repository, ImpactQueryService $impactService): int
    {
        $snapshot = $repository->active();

        if ($snapshot === null) {
            $this->error('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        $outputRoot = $this->option('output');
        $outputRoot = is_string($outputRoot) && trim($outputRoot) !== ''
            ? trim($outputRoot)
            : (string) config('logic-map.doc_export.ai.output', 'docs/logic-map-ai');
        $outputRoot = rtrim($outputRoot, '/');

        $writer = new SafeOutputWriter(base_path(), (bool) config('logic-map.export.allow_absolute_paths', false));
        $force = (bool) $this->option('force');

        try {
            $graphBundle = (new GraphJsonProjector())->project($snapshot);
            $writer->write($outputRoot.'/graph.json', $this->encode($graphBundle), $force);

            [$requested, $omittedCount] = $this->resolveTargets($snapshot);

            $usedImpactSlugs = [];
            $impactEntries = [];
            $skipped = [];

            foreach ($requested as $requestedSymbol) {
                try {
                    $report = $impactService->analyze($snapshot, symbol: $requestedSymbol);
                } catch (InvalidArgumentException $exception) {
                    $skipped[] = ['target' => $requestedSymbol, 'reason' => $exception->getMessage()];

                    continue;
                }

                $canonicalTarget = $report->changedSymbols[0]->newNodeId?->value
                    ?? $report->changedSymbols[0]->oldNodeId?->value
                    ?? $requestedSymbol;
                $projected = (new ImpactWeightProjector())->project($snapshot, $report, $canonicalTarget);
                $slug = $this->uniqueSlug($this->slug($canonicalTarget), $usedImpactSlugs);
                $writer->write($outputRoot.'/impact/'.$slug.'.json', $this->encode($projected), $force);

                $impactEntries[] = [
                    'target' => $canonicalTarget,
                    'slug' => $slug,
                    'affected_count' => count($projected['affected']),
                    'top_band' => $projected['affected'][0]['band'] ?? null,
                ];
            }

            $membersByModule = [];

            foreach ($graphBundle['nodes'] as $node) {
                if ($node['module'] !== null) {
                    $membersByModule[$node['module']][] = $node['id'];
                }
            }

            $usedModuleSlugs = [];
            $moduleEntries = [];

            foreach ($graphBundle['modules'] as $module) {
                $members = $membersByModule[$module['id']] ?? [];
                sort($members, SORT_STRING);
                $slug = $this->uniqueSlug($this->slug($module['name']), $usedModuleSlugs);
                $writer->write($outputRoot.'/modules/'.$slug.'.json', $this->encode([
                    'id' => $module['id'],
                    'encoded_id' => $module['encoded_id'],
                    'name' => $module['name'],
                    'member_count' => $module['member_count'],
                    'entrypoint_ids' => $module['entrypoint_ids'],
                    'members' => $members,
                ]), $force);
                $moduleEntries[] = ['name' => $module['name'], 'id' => $module['id'], 'slug' => $slug];
            }

            $writer->write(
                $outputRoot.'/llms.txt',
                $this->llmsTxt($snapshot, $graphBundle, $impactEntries, $omittedCount, $skipped),
                $force,
            );
            $writer->write(
                $outputRoot.'/index.md',
                $this->indexMarkdown($snapshot, $moduleEntries, $impactEntries, $omittedCount),
                $force,
            );
        } catch (Throwable $throwable) {
            $this->error('AI export failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('AI documentation bundle exported to '.$outputRoot);

        return self::SUCCESS;
    }

    /** @return array{0: list<string>, 1: int} [requested symbol identifiers, count omitted by the bound] */
    private function resolveTargets(GraphSnapshot $snapshot): array
    {
        $symbols = $this->option('symbols');

        if (is_array($symbols) && $symbols !== []) {
            $values = array_values(array_unique(array_filter(
                array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $symbols),
                static fn (string $value): bool => $value !== '',
            )));

            return [$values, 0];
        }

        $candidates = [];

        foreach (self::CHANGEABLE_KIND_PRIORITY as $kind) {
            foreach ($snapshot->graph->nodesByKind($kind) as $node) {
                $candidates[] = $node->id->value;
            }
        }

        $limit = max(0, (int) config('logic-map.doc_export.ai.max_impact_symbols', 200));
        $selected = array_slice($candidates, 0, $limit);

        return [$selected, max(0, count($candidates) - count($selected))];
    }

    private function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /**
     * @param list<array{target:string,slug:string,affected_count:int,top_band:?string}> $impactEntries
     * @param list<array{target:string,reason:string}> $skipped
     */
    private function llmsTxt(
        GraphSnapshot $snapshot,
        array $graphBundle,
        array $impactEntries,
        int $omittedCount,
        array $skipped,
    ): string {
        $bands = (array) config('logic-map.doc_export.weights.bands', []);

        $lines = [
            '# Laravel Logic Map — AI documentation bundle',
            '',
            'This bundle is a deterministic export of one indexed snapshot of a Laravel',
            'application. Use it to answer "what does changing symbol X affect, and how',
            'strongly" without running the application or the /logic-map viewer.',
            '',
            'snapshot_id: '.$snapshot->id,
            'analysis_version: '.$snapshot->analysisVersion,
            'schema_version: '.$snapshot->schemaVersion,
            'nodes: '.$snapshot->graph->countNodes().' | edges: '.$snapshot->graph->countEdges()
                .' | modules: '.count($graphBundle['modules']),
            '',
            '## How to use this bundle',
            '',
            '1. Read graph.json for the full node/edge/module inventory (canonical IDs,',
            '   kinds, module membership, entrypoints).',
            '2. For a symbol you plan to change, open the matching impact/<slug>.json file',
            '   (see the index below) to see every affected symbol with an explainable',
            '   weight: score, band, and the factor breakdown that produced it.',
            '3. Read modules/<slug>.json for a module\'s membership and entrypoints.',
            '4. A symbol not listed under "Impact files" below was not pre-computed;',
            '   re-run `logic-map:export-ai --symbols=<canonical-id>` to add it.',
            '',
            '## Weight legend',
            '',
            'score = clamp01(category x confidence x level_decay x runtime_factor), 0..1',
            '',
            '| Band | Score threshold |',
            '| --- | ---: |',
            '| critical | >= '.($bands['critical'] ?? 0.70).' |',
            '| high | >= '.($bands['high'] ?? 0.45).' |',
            '| medium | >= '.($bands['medium'] ?? 0.20).' |',
            '| low | below medium |',
            '',
            'A test-covered affected symbol drops one band as a mitigation signal (never',
            'below low); see factors.mitigated_by_test_coverage on each affected entry.',
            'Weights are heuristics over static (and optional runtime) evidence, not',
            'guarantees — treat "low" as "less certain/less central", not "safe".',
            '',
            '## File index',
            '',
            '- graph.json',
        ];

        foreach ($impactEntries as $entry) {
            $lines[] = '- impact/'.$entry['slug'].'.json — target `'.$entry['target'].'`, '
                .$entry['affected_count'].' affected, top band '.($entry['top_band'] ?? 'n/a');
        }

        if ($omittedCount > 0) {
            $lines[] = '';
            $lines[] = 'NOTE: '.$omittedCount.' changeable symbol(s) were omitted from impact/ because they'
                .' exceed logic-map.doc_export.ai.max_impact_symbols. Re-run with --symbols to target one directly.';
        }

        if ($skipped !== []) {
            $lines[] = '';
            $lines[] = 'NOTE: '.count($skipped).' requested symbol(s) could not be analyzed and were skipped:';

            foreach ($skipped as $entry) {
                $lines[] = '- '.$entry['target'].': '.$entry['reason'];
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param list<array{name:string,id:string,slug:string}> $modules
     * @param list<array{target:string,slug:string,affected_count:int,top_band:?string}> $impactEntries
     */
    private function indexMarkdown(
        GraphSnapshot $snapshot,
        array $modules,
        array $impactEntries,
        int $omittedCount,
    ): string {
        $lines = [
            '---',
            'schema_version: '.$snapshot->schemaVersion,
            'snapshot_id: '.$this->yaml($snapshot->id),
            'analysis_version: '.$this->yaml($snapshot->analysisVersion),
            '---',
            '',
            '# Logic Map AI bundle',
            '',
            'See `llms.txt` for the agent-facing preamble and weight legend.',
            '',
            '## Counts',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| nodes | '.$snapshot->graph->countNodes().' |',
            '| edges | '.$snapshot->graph->countEdges().' |',
            '| modules | '.count($modules).' |',
            '| impact symbols exported | '.count($impactEntries).' |',
            '| impact symbols omitted | '.$omittedCount.' |',
            '',
            '## Modules',
            '',
        ];

        foreach ($modules as $module) {
            $lines[] = '- ['.$this->inline($module['name']).'](modules/'.$module['slug'].'.json) — `'
                .$this->inline($module['id']).'`';
        }

        if ($modules === []) {
            $lines[] = '- None';
        }

        $lines[] = '';
        $lines[] = '## Impact bundles';
        $lines[] = '';

        foreach ($impactEntries as $entry) {
            $lines[] = '- ['.$this->inline($entry['target']).'](impact/'.$entry['slug'].'.json) — '
                .$entry['affected_count'].' affected, top band `'.($entry['top_band'] ?? 'n/a').'`';
        }

        if ($impactEntries === []) {
            $lines[] = '- None';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    private function slug(string $value): string
    {
        $slug = strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'item';
    }

    /** @param array<string,bool> $used */
    private function uniqueSlug(string $slug, array &$used): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (isset($used[$candidate])) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    private function yaml(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function inline(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], $value);
    }
}
