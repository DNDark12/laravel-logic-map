<?php

namespace DNDark\LogicMap\Analysis\Pipeline\Phases;

use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use InvalidArgumentException;

final readonly class CollectLaravelBootFactsPhase implements AnalysisPhase
{
    public function __construct(private LaravelBootInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'collect_laravel_boot';
    }

    public function dependencies(): array
    {
        return ['resolve_php'];
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        $resolved = $dependencies['resolve_php']->value ?? null;

        if (! is_array($resolved)
            || ! ($resolved['symbol_table'] ?? null) instanceof SymbolTable
            || ! is_array($resolved['parsed_files'] ?? null)) {
            throw new InvalidArgumentException('CollectLaravelBootFactsPhase requires resolved PHP symbols and files.');
        }

        foreach ($resolved['parsed_files'] as $file) {
            if (! $file instanceof ParsedFile) {
                throw new InvalidArgumentException('CollectLaravelBootFactsPhase received an invalid parsed file.');
            }
        }

        if ($context->input('boot_laravel', true) === false) {
            return new PhaseResult(
                $this->name(),
                ['boot_facts' => []],
                [],
                ['boot_fact_count' => 0, 'diagnostic_count' => 0, 'boot_skipped' => true],
            );
        }

        $inspection = $this->inspector->inspect(
            $resolved['symbol_table'],
            $resolved['parsed_files'],
        );

        return new PhaseResult(
            $this->name(),
            ['boot_facts' => $inspection->facts],
            $inspection->diagnostics,
            [
                'boot_fact_count' => count($inspection->facts),
                'diagnostic_count' => count($inspection->diagnostics),
            ],
        );
    }
}
