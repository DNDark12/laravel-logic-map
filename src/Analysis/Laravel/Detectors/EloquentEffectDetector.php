<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Laravel\ColumnMutationResolver;
use DNDark\LogicMap\Analysis\Laravel\DataEffectGraphWriter;
use DNDark\LogicMap\Analysis\Laravel\ModelTableResolver;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class EloquentEffectDetector
{
    private const READS = [
        'find', 'first', 'firstOrFail', 'get', 'pluck', 'count', 'exists', 'paginate', 'cursor', 'value',
    ];

    private const WRITES = [
        'save', 'create', 'update', 'delete', 'forceDelete', 'restore', 'increment', 'decrement', 'insert',
        'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate', 'touch', 'attach', 'detach', 'sync',
    ];

    private DataEffectGraphWriter $writer;

    private ModelTableResolver $tables;

    private ColumnMutationResolver $columns;

    public function __construct()
    {
        $this->writer = new DataEffectGraphWriter();
        $this->tables = new ModelTableResolver();
        $this->columns = new ColumnMutationResolver();
    }

    public function classifyOperation(string $method): ?string
    {
        return match (true) {
            in_array($method, self::READS, true) => 'read',
            in_array($method, self::WRITES, true) => 'write',
            default => null,
        };
    }

    public function detect(array $files, SymbolTable $symbols, \DNDark\LogicMap\Domain\Graph\KnowledgeGraph $graph): DataEffectDetectionResult
    {
        $effects = [];
        $diagnostics = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('eloquent_chain') as $fact) {
                if (($fact->attributes['origin'] ?? null) !== 'eloquent') {
                    continue;
                }

                $model = $fact->attributes['receiver_class'] ?? null;
                $operation = $this->classifyOperation($fact->attributes['terminal_method'] ?? '');

                if (! is_string($model) || $operation === null || ! $this->tables->isModel($model, $symbols)) {
                    continue;
                }

                $models = $symbols->exact($model);

                if (count($models) !== 1) {
                    continue;
                }

                $resolution = $this->tables->resolve($model, $files, $symbols);
                $diagnostics = [...$diagnostics, ...$resolution['diagnostics']];
                $edgeTypes = $operation === 'read'
                    ? [EdgeType::ReadsModel, EdgeType::ReadsTable]
                    : [EdgeType::WritesModel, EdgeType::WritesTable];
                $effects[] = $this->writer->emit(
                    $graph,
                    $fact->attributes['enclosing_symbol'],
                    $edgeTypes[0],
                    $models[0]->id,
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    $fact->attributes['expression'],
                    'eloquent_effect_detector',
                    'model',
                    $model,
                    Certainty::Certain,
                    ['operation' => $fact->attributes['terminal_method']],
                    $fact->controlContexts,
                );

                if (is_string($resolution['table'])) {
                    $table = $resolution['table'];
                    $effects[] = $this->writer->emit(
                        $graph,
                        $fact->attributes['enclosing_symbol'],
                        $edgeTypes[1],
                        $this->writer->table($graph, $table),
                        $fact->file,
                        $fact->startLine,
                        $fact->endLine,
                        $fact->attributes['expression'],
                        'eloquent_effect_detector',
                        'table',
                        $table,
                        Certainty::Certain,
                        ['operation' => $fact->attributes['terminal_method'], 'table_source' => $resolution['source']],
                        $fact->controlContexts,
                    );

                    if ($operation === 'write') {
                        [$columnEffects, $columnDiagnostics] = $this->writeColumns(
                            $file,
                            $fact,
                            $table,
                            $symbols,
                            $graph,
                        );
                        $effects = [...$effects, ...$columnEffects];
                        $diagnostics = [...$diagnostics, ...$columnDiagnostics];
                    } else {
                        foreach ($this->readColumns($fact->attributes['chain'] ?? []) as $column) {
                            $effects[] = $this->writer->emit(
                                $graph,
                                $fact->attributes['enclosing_symbol'],
                                EdgeType::ReadsColumn,
                                $this->writer->column($graph, $table, $column),
                                $fact->file,
                                $fact->startLine,
                                $fact->endLine,
                                $fact->attributes['expression'],
                                'eloquent_effect_detector',
                                'column',
                                $table.'.'.$column,
                                Certainty::Certain,
                                ['operation' => $fact->attributes['terminal_method']],
                                $fact->controlContexts,
                            );
                        }
                    }
                }
            }

            foreach ($file->facts('eloquent_property_read') as $fact) {
                $model = $fact->attributes['receiver_class'] ?? null;
                $column = $fact->attributes['column'] ?? null;

                if (! is_string($model) || ! is_string($column) || ! $this->tables->isModel($model, $symbols)) {
                    continue;
                }

                $models = $symbols->exact($model);
                $resolution = $this->tables->resolve($model, $files, $symbols);

                if (count($models) !== 1 || ! is_string($resolution['table'])) {
                    continue;
                }

                $table = $resolution['table'];

                foreach ([
                    [EdgeType::ReadsModel, $models[0]->id, 'model', $model],
                    [EdgeType::ReadsTable, $this->writer->table($graph, $table), 'table', $table],
                    [EdgeType::ReadsColumn, $this->writer->column($graph, $table, $column), 'column', $table.'.'.$column],
                ] as [$type, $target, $resourceType, $resource]) {
                    $effects[] = $this->writer->emit(
                        $graph,
                        $fact->attributes['enclosing_symbol'],
                        $type,
                        $target,
                        $fact->file,
                        $fact->startLine,
                        $fact->endLine,
                        $fact->attributes['expression'],
                        'eloquent_effect_detector',
                        $resourceType,
                        $resource,
                        Certainty::Certain,
                        ['operation' => 'property_read'],
                        $fact->controlContexts,
                    );
                }
            }
        }

        $effects = [
            ...$effects,
            ...$this->gatewayPersistedAssignmentEffects($files, $symbols, $graph),
        ];

        return new DataEffectDetectionResult($effects, $diagnostics);
    }

    private function readColumns(array $chain): array
    {
        $columns = [];

        foreach ($chain as $segment) {
            $method = $segment['method'] ?? null;
            $column = $segment['arguments'][0] ?? null;

            if (is_string($column) && in_array($method, [
                'where', 'orWhere', 'whereIn', 'whereNotIn', 'orderBy', 'groupBy', 'value', 'pluck',
            ], true)) {
                $columns[] = $column;
            }
        }

        $columns = array_values(array_unique($columns));
        sort($columns, SORT_STRING);

        return $columns;
    }

    private function gatewayPersistedAssignmentEffects(
        array $files,
        SymbolTable $symbols,
        \DNDark\LogicMap\Domain\Graph\KnowledgeGraph $graph,
    ): array {
        $persistentMethods = $this->persistentMethods($files, $symbols);
        $effects = [];
        $emitted = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->callSites as $call) {
                if (! is_string($call->receiverType) || $call->arguments === []) {
                    continue;
                }

                $argument = $call->arguments[0]['expression'] ?? null;

                if (! is_string($argument)
                    || preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $argument, $argumentMatch) !== 1) {
                    continue;
                }

                $sourceMethods = $symbols->byId($call->enclosingSymbolId);

                if (count($sourceMethods) !== 1) {
                    continue;
                }

                $variable = $argumentMatch[1];
                $model = $sourceMethods[0]->declaredParameterTypes[$variable] ?? null;

                if (! is_string($model)
                    || ! $this->gatewayConfirmsPersistence(
                        $call->receiverType,
                        $call->targetName,
                        $model,
                        $persistentMethods,
                        $symbols,
                    )) {
                    continue;
                }

                $table = $this->tables->resolve($model, $files, $symbols)['table'];

                if (! is_string($table)) {
                    continue;
                }

                $variablePattern = preg_quote($variable, '/');

                foreach ($file->facts('assignment') as $assignment) {
                    if ($assignment->startLine < $sourceMethods[0]->location->startLine
                        || $assignment->endLine > $call->startLine
                        || preg_match('/^\$'.$variablePattern.'->([A-Za-z_][A-Za-z0-9_]*)$/', $assignment->attributes['target'], $columnMatch) !== 1) {
                        continue;
                    }

                    $column = $columnMatch[1];
                    $key = implode("\0", [
                        $call->enclosingSymbolId->value,
                        $table,
                        $column,
                        $assignment->file,
                        $assignment->startLine,
                        $assignment->endLine,
                    ]);

                    if (isset($emitted[$key])) {
                        continue;
                    }

                    $emitted[$key] = true;
                    $effects[] = $this->writer->emit(
                        $graph,
                        $call->enclosingSymbolId->value,
                        EdgeType::WritesColumn,
                        $this->writer->column($graph, $table, $column),
                        $assignment->file,
                        $assignment->startLine,
                        $assignment->endLine,
                        $assignment->attributes['expression'],
                        'eloquent_effect_detector',
                        'column',
                        $table.'.'.$column,
                        Certainty::Certain,
                        [
                            'operation' => 'save',
                            'persistence_confirmed_by' => $call->receiverType.'::'.$call->targetName,
                        ],
                        $assignment->controlContexts,
                    );
                }
            }
        }

        return $effects;
    }

    private function persistentMethods(array $files, SymbolTable $symbols): array
    {
        $methods = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('eloquent_chain') as $fact) {
                if (($fact->attributes['origin'] ?? null) !== 'eloquent'
                    || ($fact->attributes['terminal_method'] ?? null) !== 'save'
                    || ! is_string($fact->attributes['receiver_variable'] ?? null)) {
                    continue;
                }

                $methodId = \DNDark\LogicMap\Domain\Graph\NodeId::fromString(
                    $fact->attributes['enclosing_symbol'],
                );
                $definitions = $symbols->byId($methodId);
                $variable = $fact->attributes['receiver_variable'];
                $model = count($definitions) === 1
                    ? ($definitions[0]->declaredParameterTypes[$variable] ?? null)
                    : null;

                if (is_string($model) && is_string($definitions[0]->qualifiedName ?? null)) {
                    $methods[$definitions[0]->qualifiedName][] = $model;
                }
            }
        }

        return $methods;
    }

    private function gatewayConfirmsPersistence(
        string $receiverType,
        string $method,
        string $model,
        array $persistentMethods,
        SymbolTable $symbols,
    ): bool {
        $classes = [
            ...$symbols->exact($receiverType),
            ...$symbols->implementations($receiverType),
        ];

        foreach ($classes as $class) {
            if (! is_string($class->qualifiedName)) {
                continue;
            }

            $models = $persistentMethods[$class->qualifiedName.'::'.$method] ?? [];

            if (in_array($model, $models, true)) {
                return true;
            }
        }

        return false;
    }

    private function writeColumns(
        ParsedFile $file,
        $fact,
        string $table,
        SymbolTable $symbols,
        \DNDark\LogicMap\Domain\Graph\KnowledgeGraph $graph,
    ): array {
        $operation = $fact->attributes['terminal_method'];
        $columns = $this->columns->resolve($operation, $fact->attributes['arguments'] ?? []);
        $effects = [];
        $diagnostics = [];

        if ($operation === 'save' && is_string($fact->attributes['receiver_variable'] ?? null)) {
            $variable = preg_quote($fact->attributes['receiver_variable'], '/');
            $sourceMethods = $symbols->byId(\DNDark\LogicMap\Domain\Graph\NodeId::fromString(
                $fact->attributes['enclosing_symbol'],
            ));

            foreach ($file->facts('assignment') as $assignment) {
                if (count($sourceMethods) !== 1
                    || $assignment->startLine < $sourceMethods[0]->location->startLine
                    || $assignment->endLine > $fact->endLine
                    || preg_match('/^\$'.$variable.'->([A-Za-z_][A-Za-z0-9_]*)$/', $assignment->attributes['target'], $matches) !== 1) {
                    continue;
                }

                $effects[] = $this->writer->emit(
                    $graph,
                    $fact->attributes['enclosing_symbol'],
                    EdgeType::WritesColumn,
                    $this->writer->column($graph, $table, $matches[1]),
                    $assignment->file,
                    $assignment->startLine,
                    $assignment->endLine,
                    $assignment->attributes['expression'],
                    'eloquent_effect_detector',
                    'column',
                    $table.'.'.$matches[1],
                    Certainty::Certain,
                    ['operation' => 'save', 'receiver_variable' => $fact->attributes['receiver_variable']],
                    $assignment->controlContexts,
                );
            }

            return [$effects, $diagnostics];
        }

        if ($columns === null) {
            if (in_array($operation, [
                'create', 'update', 'insert', 'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate',
            ], true)) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::UnknownColumnSet,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'Eloquent write has a dynamic or unsupported column set.',
                    ['operation' => $operation, 'table' => $table],
                );
            }

            return [$effects, $diagnostics];
        }

        foreach ($columns as $column) {
            $effects[] = $this->writer->emit(
                $graph,
                $fact->attributes['enclosing_symbol'],
                EdgeType::WritesColumn,
                $this->writer->column($graph, $table, $column),
                $fact->file,
                $fact->startLine,
                $fact->endLine,
                $fact->attributes['expression'],
                'eloquent_effect_detector',
                'column',
                $table.'.'.$column,
                Certainty::Certain,
                ['operation' => $operation],
                $fact->controlContexts,
            );
        }

        return [$effects, $diagnostics];
    }
}
