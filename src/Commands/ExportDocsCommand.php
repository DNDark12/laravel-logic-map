<?php

namespace DNDark\LogicMap\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Projectors\ModuleWorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowDossierMarkdownProjector;
use DNDark\LogicMap\Services\Workflow\WorkflowQueryService;
use DNDark\LogicMap\Support\SafeOutputWriter;
use Throwable;
use Illuminate\Console\Command;

/**
 * V2-native batch documentation export. Orchestration only: it reads the active
 * snapshot, enumerates module nodes, builds each module through
 * WorkflowQueryService::buildModule() (never modelling members as changed
 * symbols), and writes deterministic, repository-relative Markdown through
 * SafeOutputWriter. It never invokes impact analysis for baseline docs.
 */
final class ExportDocsCommand extends Command
{
    protected $signature = 'logic-map:export-docs
                            {--output= : Repository-relative output directory (defaults to logic-map.doc_export.output)}
                            {--force : Overwrite existing files}';

    protected $description = 'Export the active Laravel Logic Map V2 snapshot as AI-readable module and workflow dossiers';

    public function handle(SemanticGraphRepository $repository, WorkflowQueryService $service): int
    {
        $snapshot = $repository->active();

        if ($snapshot === null) {
            $this->error('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        $outputRoot = $this->option('output');
        $outputRoot = is_string($outputRoot) && trim($outputRoot) !== ''
            ? trim($outputRoot)
            : (string) config('logic-map.doc_export.output', 'docs/logic-map');
        $outputRoot = rtrim($outputRoot, '/');

        $writer = new SafeOutputWriter(
            base_path(),
            (bool) config('logic-map.export.allow_absolute_paths', false),
        );
        $force = (bool) $this->option('force');
        $generatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $modules = array_slice(
                $snapshot->graph->nodesByKind(NodeKind::Module),
                0,
                max(0, (int) config('logic-map.doc_export.max_modules', 100)),
            );
            $maxWorkflows = max(0, (int) config('logic-map.doc_export.max_workflows', 500));

            $moduleEntries = [];
            $workflowFiles = [];
            $usedModuleSlugs = [];
            $usedWorkflowSlugs = [];
            $seenWorkflows = [];
            $workflowCount = 0;

            foreach ($modules as $module) {
                $moduleWorkflow = $service->buildModule($snapshot, $module->id);
                $evidence = [];

                foreach ($moduleWorkflow->entryWorkflows as $workflow) {
                    $evidence[$workflow->id->value] = $service->evidence($snapshot, $workflow);
                }

                $slug = $this->uniqueSlug($this->slug($moduleWorkflow->name), $usedModuleSlugs);
                $writer->write(
                    $outputRoot.'/modules/'.$slug.'.md',
                    (new ModuleWorkflowMarkdownProjector())->project(
                        $moduleWorkflow,
                        $snapshot->id,
                        $generatedAt,
                        $evidence,
                    ),
                    $force,
                );
                $moduleEntries[] = ['name' => $moduleWorkflow->name, 'id' => $module->id->value, 'slug' => $slug];

                foreach ($moduleWorkflow->entryWorkflows as $workflow) {
                    if ($workflowCount >= $maxWorkflows || isset($seenWorkflows[$workflow->entrypoint->value])) {
                        continue;
                    }

                    $seenWorkflows[$workflow->entrypoint->value] = true;
                    $workflowFiles[] = [$workflow, $evidence[$workflow->id->value] ?? []];
                    $workflowCount++;
                }
            }

            foreach ($workflowFiles as [$workflow, $workflowEvidence]) {
                /** @var WorkflowDefinition $workflow */
                $slug = $this->uniqueSlug($this->slug($workflow->entrypoint->value), $usedWorkflowSlugs);
                $writer->write(
                    $outputRoot.'/workflows/'.$slug.'.md',
                    (new WorkflowDossierMarkdownProjector())->project(
                        $workflow,
                        $snapshot->id,
                        $generatedAt,
                        $workflowEvidence,
                    ),
                    $force,
                );
            }

            $writer->write(
                $outputRoot.'/overview.md',
                $this->overview($snapshot, $moduleEntries, count($workflowFiles), $generatedAt),
                $force,
            );
        } catch (Throwable $throwable) {
            $this->error('Export failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Documentation exported to '.$outputRoot);

        return self::SUCCESS;
    }

    /** @param list<array{name:string,id:string,slug:string}> $modules */
    private function overview(
        GraphSnapshot $snapshot,
        array $modules,
        int $workflowCount,
        DateTimeImmutable $generatedAt,
    ): string {
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.$this->yaml($snapshot->id),
            'analysis_version: '.$this->yaml($snapshot->analysisVersion),
            'generated_at: '.$this->yaml($generatedAt->format(DATE_ATOM)),
            '---',
            '',
            '# Logic Map overview',
            '',
            '## Counts',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| nodes | '.$snapshot->graph->countNodes().' |',
            '| edges | '.$snapshot->graph->countEdges().' |',
            '| modules | '.count($modules).' |',
            '| workflow dossiers | '.$workflowCount.' |',
            '',
            '## Modules',
            '',
        ];

        foreach ($modules as $module) {
            $lines[] = '- ['.$this->inline($module['name']).'](modules/'.$module['slug'].'.md) — `'
                .$this->inline($module['id']).'`';
        }

        if ($modules === []) {
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
