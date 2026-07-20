<?php

namespace DNDark\LogicMap\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Projectors\ModuleWorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowDossierMarkdownProjector;
use DNDark\LogicMap\Services\Workflow\WorkflowQueryService;
use DNDark\LogicMap\Support\RelativePath;
use DNDark\LogicMap\Support\SafeOutputWriter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ExportDocsCommand extends Command
{
    protected $signature = 'logic-map:export-docs
                            {--output= : Repository-relative documentation directory}
                            {--force : Overwrite managed documentation files}';

    protected $description = 'Export V2 module and workflow dossiers for engineers and AI tools';

    public function handle(SemanticGraphRepository $repository, WorkflowQueryService $workflowService): int
    {
        $snapshot = $repository->active();

        if ($snapshot === null) {
            $this->error('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        try {
            $output = $this->outputDirectory();
            $absoluteOutput = $this->absoluteOutputDirectory($output);
            $force = (bool) $this->option('force');

            if (is_dir($absoluteOutput) && ! $force) {
                throw new RuntimeException('Documentation directory already exists; pass --force to overwrite it.');
            }

            $writer = new SafeOutputWriter(
                base_path(),
                (bool) config('logic-map.export.allow_absolute_paths', false),
            );
            $generatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $modules = array_slice(
                $snapshot->graph->nodesByKind(NodeKind::Module),
                0,
                max(1, (int) config('logic-map.doc_export.max_modules', 100)),
            );
            $moduleRows = [];
            $workflowRows = [];
            $workflowIds = [];
            $moduleSlugs = [];
            $workflowLimit = max(1, (int) config('logic-map.doc_export.max_workflows', 500));

            foreach ($modules as $module) {
                $moduleWorkflow = $workflowService->buildModule($snapshot, $module->id);
                $evidence = [];

                foreach ($moduleWorkflow->entryWorkflows as $workflow) {
                    $evidence[$workflow->id->value] = $workflowService->evidence($snapshot, $workflow);
                }

                $moduleSlug = $this->uniqueSlug($module->name, $module->id->value, $moduleSlugs);
                $modulePath = 'modules/'.$moduleSlug.'.md';
                $writer->write(
                    $this->join($output, $modulePath),
                    (new ModuleWorkflowMarkdownProjector())->project(
                        $moduleWorkflow,
                        $snapshot->id,
                        $generatedAt,
                        $evidence,
                    ),
                    $force,
                );
                $moduleRows[] = [
                    'id' => $module->id->value,
                    'name' => $module->name,
                    'path' => $modulePath,
                    'entrypoint_count' => count($moduleWorkflow->entryWorkflows),
                ];

                foreach ($moduleWorkflow->entryWorkflows as $workflow) {
                    if (isset($workflowIds[$workflow->entrypoint->value]) || count($workflowRows) >= $workflowLimit) {
                        continue;
                    }

                    $workflowIds[$workflow->entrypoint->value] = true;
                    $workflowSlug = $this->slug($workflow->entrypoint->value).'-'.substr(
                        hash('sha256', $workflow->entrypoint->value),
                        0,
                        8,
                    );
                    $workflowPath = 'workflows/'.$workflowSlug.'.md';
                    $writer->write(
                        $this->join($output, $workflowPath),
                        (new WorkflowDossierMarkdownProjector())->project(
                            $workflow,
                            $snapshot->id,
                            $generatedAt,
                            $evidence[$workflow->id->value] ?? [],
                        ),
                        $force,
                    );
                    $workflowRows[] = [
                        'entrypoint_id' => $workflow->entrypoint->value,
                        'path' => $workflowPath,
                        'step_count' => count($workflow->steps),
                    ];
                }
            }

            $writer->write(
                $this->join($output, 'overview.md'),
                $this->overview($snapshot->id, $generatedAt, $moduleRows, $workflowRows, [
                    'nodes' => $snapshot->graph->countNodes(),
                    'edges' => $snapshot->graph->countEdges(),
                    'evidence' => $snapshot->graph->countEvidence(),
                ]),
                $force,
            );

            $this->info('Documentation exported to '.$absoluteOutput);
            $this->line('Modules: '.count($moduleRows));
            $this->line('Workflows: '.count($workflowRows));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Documentation export failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function outputDirectory(): string
    {
        $option = $this->option('output');
        $output = is_string($option) && trim($option) !== ''
            ? trim($option)
            : (string) config('logic-map.doc_export.output', 'docs/logic-map');

        if ($this->isAbsolute($output)) {
            if (! (bool) config('logic-map.export.allow_absolute_paths', false)) {
                throw new \InvalidArgumentException('Absolute output paths are disabled.');
            }

            return rtrim(str_replace('\\', '/', $output), '/');
        }

        return RelativePath::normalize($output);
    }

    private function absoluteOutputDirectory(string $output): string
    {
        return $this->isAbsolute($output)
            ? $output
            : rtrim(str_replace('\\', '/', base_path()), '/').'/'.$output;
    }

    private function join(string $directory, string $file): string
    {
        return rtrim($directory, '/').'/'.$file;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function uniqueSlug(string $name, string $id, array &$used): string
    {
        $base = $this->slug($name);
        $slug = $base;

        if (isset($used[$slug])) {
            $slug .= '-'.substr(hash('sha256', $id), 0, 8);
        }

        $used[$slug] = true;

        return $slug;
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 100) : 'artifact';
    }

    private function overview(
        string $snapshotId,
        DateTimeImmutable $generatedAt,
        array $modules,
        array $workflows,
        array $counts,
    ): string {
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.json_encode($snapshotId, JSON_THROW_ON_ERROR),
            'generated_at: '.json_encode($generatedAt->format(DATE_ATOM), JSON_THROW_ON_ERROR),
            '---',
            '',
            '# Laravel Logic Map',
            '',
            '## Index summary',
            '',
            '| Symbols | Relations | Evidence | Modules | Workflows |',
            '| ---: | ---: | ---: | ---: | ---: |',
            '| '.$counts['nodes'].' | '.$counts['edges'].' | '.$counts['evidence'].' | '.count($modules).' | '.count($workflows).' |',
            '',
            '## Modules',
            '',
        ];

        foreach ($modules as $module) {
            $lines[] = '- ['.$module['name'].']('.$module['path'].') — `'.$module['id'].'`, '.$module['entrypoint_count'].' entry workflows';
        }

        if ($modules === []) {
            $lines[] = '- None';
        }

        $lines = [...$lines, '', '## Workflows', ''];

        foreach ($workflows as $workflow) {
            $lines[] = '- [`'.$workflow['entrypoint_id'].'`]('.$workflow['path'].') — '.$workflow['step_count'].' steps';
        }

        if ($workflows === []) {
            $lines[] = '- None';
        }

        return implode("\n", $lines)."\n";
    }
}
