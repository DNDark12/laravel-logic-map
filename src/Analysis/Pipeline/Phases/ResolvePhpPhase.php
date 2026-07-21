<?php

namespace DNDark\LogicMap\Analysis\Pipeline\Phases;

use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use InvalidArgumentException;

final class ResolvePhpPhase implements AnalysisPhase
{
    public function name(): string
    {
        return 'resolve_php';
    }

    public function dependencies(): array
    {
        return ['parse_php'];
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        $files = $dependencies['parse_php']->value ?? null;

        if (! is_array($files)) {
            throw new InvalidArgumentException('ResolvePhpPhase requires parsed files.');
        }

        $symbols = new SymbolTable();

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                throw new InvalidArgumentException('ResolvePhpPhase received an invalid parsed file.');
            }

            foreach ($file->symbols as $symbol) {
                $symbols->add($symbol);
            }
        }

        $diagnostics = (new StructuralGraphBuilder($symbols))->build($files, $context->graph);
        $diagnostics = [
            ...$diagnostics,
            ...(new CallGraphBuilder(new CallTargetResolver($symbols), $symbols))->build($files, $context->graph),
        ];

        return new PhaseResult($this->name(), [
            'parsed_files' => $files,
            'symbol_table' => $symbols,
        ], $diagnostics, [
            'symbol_count' => count($symbols->all()),
            'node_count' => count($context->graph->nodes()),
            'edge_count' => count($context->graph->edges()),
        ]);
    }
}
