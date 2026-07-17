<?php

namespace DNDark\LogicMap\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
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
