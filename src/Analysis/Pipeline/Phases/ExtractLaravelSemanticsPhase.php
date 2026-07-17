<?php

namespace DNDark\LogicMap\Analysis\Pipeline\Phases;

use DNDark\LogicMap\Analysis\Laravel\LaravelSemanticAnalyzer;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use InvalidArgumentException;

final readonly class ExtractLaravelSemanticsPhase implements AnalysisPhase
{
    public function __construct(private LaravelSemanticAnalyzer $analyzer) {}

    public function name(): string
    {
        return 'extract_laravel_semantics';
    }

    public function dependencies(): array
    {
        return ['resolve_php', 'collect_laravel_boot'];
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        $resolved = $dependencies['resolve_php']->value ?? null;
        $boot = $dependencies['collect_laravel_boot']->value ?? null;

        if (! is_array($resolved)
            || ! ($resolved['symbol_table'] ?? null) instanceof SymbolTable
            || ! is_array($resolved['parsed_files'] ?? null)
            || ! is_array($boot)
            || ! is_array($boot['boot_facts'] ?? null)) {
            throw new InvalidArgumentException(
                'ExtractLaravelSemanticsPhase requires resolved PHP inputs and Laravel boot facts.',
            );
        }

        foreach ($resolved['parsed_files'] as $file) {
            if (! $file instanceof ParsedFile) {
                throw new InvalidArgumentException('ExtractLaravelSemanticsPhase received an invalid parsed file.');
            }
        }

        $result = $this->analyzer->analyze(
            $resolved['parsed_files'],
            $resolved['symbol_table'],
            $boot['boot_facts'],
            $context->graph,
        );

        return new PhaseResult(
            $this->name(),
            $result['outputs'],
            $result['diagnostics'],
            ['detectors' => $result['metrics']],
        );
    }
}
