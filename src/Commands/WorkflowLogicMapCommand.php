<?php

namespace DNDark\LogicMap\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Projectors\ModuleWorkflowJsonProjector;
use DNDark\LogicMap\Projectors\ModuleWorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\SymbolWorkflowCollectionJsonProjector;
use DNDark\LogicMap\Projectors\SymbolWorkflowCollectionMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowJsonProjector;
use DNDark\LogicMap\Projectors\WorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowMermaidProjector;
use DNDark\LogicMap\Services\Workflow\WorkflowQueryService;
use DNDark\LogicMap\Support\SafeOutputWriter;
use Illuminate\Console\Command;
use Throwable;

final class WorkflowLogicMapCommand extends Command
{
    protected $signature = 'logic-map:workflow
                            {symbol : Canonical node ID or exact qualified name}
                            {--format=json : json, markdown, or mermaid}
                            {--output= : Repository-relative output file}
                            {--force : Overwrite an existing output file}';

    protected $description = 'Project an evidence-backed Laravel Logic Map V2 workflow';

    public function handle(SemanticGraphRepository $repository, WorkflowQueryService $service): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'markdown', 'mermaid'], true)) {
            $this->error('Invalid workflow format; use json, markdown, or mermaid.');

            return self::FAILURE;
        }

        $snapshot = $repository->active();

        if ($snapshot === null) {
            $this->error('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        try {
            $entrypoint = $service->resolve($snapshot, (string) $this->argument('symbol'));

            if ($snapshot->graph->findNode($entrypoint)?->kind === NodeKind::Module) {
                $moduleWorkflow = $service->buildModule($snapshot, $entrypoint);
                $evidence = [];

                foreach ($moduleWorkflow->entryWorkflows as $workflow) {
                    $evidence[$workflow->id->value] = $service->evidence($snapshot, $workflow);
                }

                $content = match ($format) {
                    'json' => json_encode(
                        (new ModuleWorkflowJsonProjector())->project($moduleWorkflow, $snapshot->id, $evidence),
                        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    )."\n",
                    'markdown' => (new ModuleWorkflowMarkdownProjector())->project(
                        $moduleWorkflow,
                        $snapshot->id,
                        new DateTimeImmutable('now', new DateTimeZone('UTC')),
                        $evidence,
                    ),
                    'mermaid' => throw new \InvalidArgumentException(
                        'Module workflows contain multiple diagrams; use markdown or json.',
                    ),
                };

                return $this->emit($content);
            }

            $collection = $service->buildSymbolCollection($snapshot, $entrypoint);

            if ($collection !== []) {
                $selection = $snapshot->graph->findNode($entrypoint);

                if ($selection === null) {
                    throw new \InvalidArgumentException('Workflow selection does not exist in the active snapshot.');
                }

                $evidence = [];

                foreach ($collection as $workflow) {
                    $evidence[$workflow->id->value] = $service->evidence($snapshot, $workflow);
                }

                $content = match ($format) {
                    'json' => json_encode(
                        (new SymbolWorkflowCollectionJsonProjector())->project(
                            $selection,
                            $collection,
                            $snapshot->id,
                            $evidence,
                        ),
                        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    )."\n",
                    'markdown' => (new SymbolWorkflowCollectionMarkdownProjector())->project(
                        $selection,
                        $collection,
                        $snapshot->id,
                        new DateTimeImmutable('now', new DateTimeZone('UTC')),
                        $evidence,
                    ),
                    'mermaid' => throw new \InvalidArgumentException(
                        'Symbol workflow collections contain multiple diagrams; use markdown or json.',
                    ),
                };

                return $this->emit($content);
            }

            $workflow = $service->build($snapshot, $entrypoint);
            $evidence = $service->evidence($snapshot, $workflow);
            $content = match ($format) {
                'json' => json_encode(
                    (new WorkflowJsonProjector())->project($workflow, $snapshot->id, $evidence),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )."\n",
                'markdown' => (new WorkflowMarkdownProjector())->project(
                    $workflow,
                    $snapshot->id,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    $evidence,
                ),
                'mermaid' => (new WorkflowMermaidProjector())->project($workflow),
            };

            return $this->emit($content);
        } catch (Throwable $throwable) {
            $this->error('Workflow failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function emit(string $content): int
    {
        $output = $this->option('output');

        if (! is_string($output) || trim($output) === '') {
            $this->output->write($content);

            return self::SUCCESS;
        }

        $path = (new SafeOutputWriter(
            base_path(),
            (bool) config('logic-map.export.allow_absolute_paths', false),
        ))->write($output, $content, (bool) $this->option('force'));
        $this->info('Written: '.$path);

        return self::SUCCESS;
    }
}
