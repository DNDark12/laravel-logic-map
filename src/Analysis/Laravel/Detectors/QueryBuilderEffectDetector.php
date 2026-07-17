<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Laravel\ColumnMutationResolver;
use DNDark\LogicMap\Analysis\Laravel\DataEffectGraphWriter;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class QueryBuilderEffectDetector
{
    private DataEffectGraphWriter $writer;

    private ColumnMutationResolver $columns;

    private EloquentEffectDetector $operations;

    public function __construct()
    {
        $this->writer = new DataEffectGraphWriter();
        $this->columns = new ColumnMutationResolver();
        $this->operations = new EloquentEffectDetector();
    }

    public function detect(array $files, SymbolTable $symbols, KnowledgeGraph $graph): DataEffectDetectionResult
    {
        $effects = [];
        $diagnostics = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('eloquent_chain') as $fact) {
                $origin = $fact->attributes['origin'] ?? null;

                if ($origin === 'raw_sql') {
                    [$rawEffects, $rawDiagnostics] = $this->rawSql($fact, $graph);
                    $effects = [...$effects, ...$rawEffects];
                    $diagnostics = [...$diagnostics, ...$rawDiagnostics];

                    continue;
                }

                if ($origin !== 'query_builder') {
                    continue;
                }

                $table = $fact->attributes['table'] ?? null;

                if (! is_string($table) || trim($table) === '') {
                    $diagnostics[] = new Diagnostic(
                        DiagnosticCode::UnknownTable,
                        'laravel_semantics',
                        $fact->file,
                        $fact->startLine,
                        $fact->endLine,
                        'Query Builder table name is dynamic.',
                        ['expression' => $fact->attributes['expression']],
                    );

                    continue;
                }

                $classification = $this->operations->classifyOperation($fact->attributes['terminal_method']);

                if ($classification === null) {
                    continue;
                }

                $edge = $classification === 'read' ? EdgeType::ReadsTable : EdgeType::WritesTable;
                $effects[] = $this->writer->emit(
                    $graph,
                    $fact->attributes['enclosing_symbol'],
                    $edge,
                    $this->writer->table($graph, $table),
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    $fact->attributes['expression'],
                    'query_builder_effect_detector',
                    'table',
                    $table,
                    Certainty::Certain,
                    ['operation' => $fact->attributes['terminal_method']],
                    $fact->controlContexts,
                );

                if ($classification !== 'write') {
                    continue;
                }

                $columns = $this->columns->resolve(
                    $fact->attributes['terminal_method'],
                    $fact->attributes['arguments'] ?? [],
                );

                if ($columns === null && in_array($fact->attributes['terminal_method'], [
                    'create', 'update', 'insert', 'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate',
                ], true)) {
                    $diagnostics[] = new Diagnostic(
                        DiagnosticCode::UnknownColumnSet,
                        'laravel_semantics',
                        $fact->file,
                        $fact->startLine,
                        $fact->endLine,
                        'Query Builder write has a dynamic or unsupported column set.',
                        ['operation' => $fact->attributes['terminal_method'], 'table' => $table],
                    );

                    continue;
                }

                foreach ($columns ?? [] as $column) {
                    $effects[] = $this->writer->emit(
                        $graph,
                        $fact->attributes['enclosing_symbol'],
                        EdgeType::WritesColumn,
                        $this->writer->column($graph, $table, $column),
                        $fact->file,
                        $fact->startLine,
                        $fact->endLine,
                        $fact->attributes['expression'],
                        'query_builder_effect_detector',
                        'column',
                        $table.'.'.$column,
                        Certainty::Certain,
                        ['operation' => $fact->attributes['terminal_method']],
                        $fact->controlContexts,
                    );
                }
            }
        }

        return new DataEffectDetectionResult($effects, $diagnostics);
    }

    private function rawSql($fact, KnowledgeGraph $graph): array
    {
        $sql = $fact->attributes['raw_sql'] ?? null;

        if (! is_string($sql)) {
            return [[], [$this->rawDiagnostic($fact)]];
        }

        $patterns = [
            ['read', '/^\s*select\b.+?\bfrom\s+([A-Za-z_][A-Za-z0-9_]*)/is'],
            ['write', '/^\s*update\s+([A-Za-z_][A-Za-z0-9_]*)\b/is'],
            ['write', '/^\s*insert\s+into\s+([A-Za-z_][A-Za-z0-9_]*)\b/is'],
            ['write', '/^\s*delete\s+from\s+([A-Za-z_][A-Za-z0-9_]*)\b/is'],
        ];

        foreach ($patterns as [$operation, $pattern]) {
            if (preg_match($pattern, $sql, $matches) !== 1) {
                continue;
            }

            $table = $matches[1];
            $effect = $operation === 'read' ? EdgeType::ReadsTable : EdgeType::WritesTable;

            return [[
                $this->writer->emit(
                    $graph,
                    $fact->attributes['enclosing_symbol'],
                    $effect,
                    $this->writer->table($graph, $table),
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    $fact->attributes['expression'],
                    'query_builder_effect_detector',
                    'table',
                    $table,
                    Certainty::Probable,
                    ['operation' => 'raw_sql'],
                    $fact->controlContexts,
                ),
            ], []];
        }

        return [[], [$this->rawDiagnostic($fact)]];
    }

    private function rawDiagnostic($fact): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::UnparsedRawSql,
            'laravel_semantics',
            $fact->file,
            $fact->startLine,
            $fact->endLine,
            'Raw SQL could not be classified conservatively.',
            ['method' => $fact->attributes['terminal_method'] ?? null],
        );
    }
}
