<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Support\Str;

final class ModelTableResolver
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    public function resolve(string $model, array $files, SymbolTable $symbols): array
    {
        $definitions = $symbols->exact($model);

        if (count($definitions) !== 1) {
            return ['table' => null, 'source' => null, 'diagnostics' => [
                $this->diagnostic(null, $model, 'model_symbol_unavailable'),
            ]];
        }

        $definition = $definitions[0];
        $tableFacts = $this->tableFacts($definition, $files);

        if ($tableFacts !== []) {
            $value = $tableFacts[0]->attributes['value'] ?? null;
            $literal = is_string($value) ? $this->literal($value) : null;

            if ($literal !== null && $literal !== '') {
                return ['table' => $literal, 'source' => 'property_default', 'diagnostics' => []];
            }

            return ['table' => null, 'source' => null, 'diagnostics' => [
                $this->diagnostic($definition, $model, 'dynamic_table_property'),
            ]];
        }

        if ($symbols->methods($model, 'getTable') !== []) {
            return ['table' => null, 'source' => null, 'diagnostics' => [
                $this->diagnostic($definition, $model, 'get_table_override'),
            ]];
        }

        $base = class_basename($model);

        if ($base === '') {
            return ['table' => null, 'source' => null, 'diagnostics' => [
                $this->diagnostic($definition, $model, 'class_name_unavailable'),
            ]];
        }

        return [
            'table' => Str::snake(Str::pluralStudly($base)),
            'source' => 'convention',
            'diagnostics' => [],
        ];
    }

    public function isModel(string $class, SymbolTable $symbols, array $visited = []): bool
    {
        if ($class === self::MODEL) {
            return true;
        }

        if (isset($visited[$class])) {
            return false;
        }

        $visited[$class] = true;
        $definitions = $symbols->exact($class);

        if (count($definitions) !== 1) {
            return false;
        }

        foreach ($definitions[0]->attributes['extends'] ?? [] as $parent) {
            if ($this->isModel($parent, $symbols, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function tableFacts(SymbolDefinition $model, array $files): array
    {
        $facts = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile || $file->relativePath !== $model->location->file) {
                continue;
            }

            foreach ($file->facts('property_default') as $fact) {
                if (($fact->attributes['property'] ?? null) === 'table'
                    && $fact->startLine >= $model->location->startLine
                    && $fact->endLine <= $model->location->endLine) {
                    $facts[] = $fact;
                }
            }
        }

        return $facts;
    }

    private function literal(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) < 2 || ! in_array($value[0], ["'", '"'], true) || $value[-1] !== $value[0]) {
            return null;
        }

        return stripcslashes(substr($value, 1, -1));
    }

    private function diagnostic(?SymbolDefinition $model, string $class, string $reason): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::UnknownTable,
            'laravel_semantics',
            $model?->location->file,
            $model?->location->startLine,
            $model?->location->endLine,
            'Model table could not be resolved without executing application code.',
            ['model' => $class, 'reason' => $reason],
        );
    }
}
