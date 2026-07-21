<?php

namespace DNDark\LogicMap\Analysis\Pipeline\Phases;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use InvalidArgumentException;

final readonly class ParsePhpPhase implements AnalysisPhase
{
    public function __construct(private PhpFileParser $parser)
    {
    }

    public function name(): string
    {
        return 'parse_php';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        $sources = $context->input('sources', []);

        if (! is_array($sources)) {
            throw new InvalidArgumentException('ParsePhpPhase requires an associative sources input.');
        }

        ksort($sources, SORT_STRING);
        $files = [];
        $diagnostics = [];

        foreach ($sources as $path => $source) {
            if (! is_string($path) || ! is_string($source)) {
                throw new InvalidArgumentException('ParsePhpPhase source paths and contents must be strings.');
            }

            $file = $this->parser->parse($path, $source);
            $files[] = $file;
            $diagnostics = [...$diagnostics, ...$file->diagnostics];
        }

        return new PhaseResult($this->name(), $files, $diagnostics, [
            'file_count' => count($files),
        ]);
    }
}
