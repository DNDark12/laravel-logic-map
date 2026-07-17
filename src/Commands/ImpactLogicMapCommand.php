<?php

namespace DNDark\LogicMap\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Projectors\ImpactJsonProjector;
use DNDark\LogicMap\Projectors\ImpactMarkdownProjector;
use DNDark\LogicMap\Services\Impact\ImpactQueryService;
use DNDark\LogicMap\Support\SafeOutputWriter;
use Illuminate\Console\Command;
use Throwable;

final class ImpactLogicMapCommand extends Command
{
    protected $signature = 'logic-map:impact
                            {symbol? : Canonical node ID or exact qualified name}
                            {--base= : Base Git ref}
                            {--head= : Head Git ref}
                            {--format=json : json or markdown}
                            {--output= : Repository-relative output file}
                            {--force : Overwrite an existing output file}';

    protected $description = 'Analyze evidence-backed Laravel Logic Map V2 change impact';

    public function handle(SemanticGraphRepository $repository, ImpactQueryService $service): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'markdown'], true)) {
            $this->error('Invalid impact format; use json or markdown.');

            return self::FAILURE;
        }

        $snapshot = $repository->active();

        if ($snapshot === null) {
            $this->error('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        try {
            $symbol = $this->argument('symbol');
            $report = $service->analyze(
                $snapshot,
                is_string($symbol) && trim($symbol) !== '' ? $symbol : null,
                $this->stringOption('base'),
                $this->stringOption('head'),
            );
            $target = is_string($symbol) && trim($symbol) !== ''
                ? $symbol
                : (($this->stringOption('base') ?? 'HEAD~1').'..'.($this->stringOption('head') ?? 'HEAD'));
            $content = match ($format) {
                'json' => json_encode(
                    (new ImpactJsonProjector())->project($report),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )."\n",
                'markdown' => (new ImpactMarkdownProjector())->project(
                    $report,
                    $snapshot->id,
                    $target,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ),
            };

            return $this->emit($content);
        } catch (Throwable $throwable) {
            $this->error('Impact failed: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
