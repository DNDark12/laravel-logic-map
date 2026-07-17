<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Closure;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Foundation\Application;
use Throwable;
use UnexpectedValueException;

final class LaravelBootInspector
{
    private readonly Closure $applicationSupplier;

    public function __construct(
        callable $applicationSupplier,
        private readonly array $collectors,
    ) {
        $this->applicationSupplier = Closure::fromCallable($applicationSupplier);

        foreach ($collectors as $collector) {
            if (! $collector instanceof BootCollector) {
                throw new UnexpectedValueException('Laravel boot collectors must implement BootCollector.');
            }
        }
    }

    public function inspect(?SymbolTable $symbols = null, array $parsedFiles = []): BootInspectionResult
    {
        try {
            $application = ($this->applicationSupplier)();

            if (! $application instanceof Application) {
                throw new UnexpectedValueException('Application supplier did not return a Laravel application.');
            }
        } catch (Throwable $exception) {
            return new BootInspectionResult([], [new Diagnostic(
                DiagnosticCode::BootInspectionFailed,
                'laravel_boot',
                null,
                null,
                null,
                'Laravel application could not be inspected.',
                [
                    'stage' => 'application_boot',
                    'exception' => $exception::class,
                ],
            )]);
        }

        $facts = [];
        $diagnostics = [];

        foreach ($this->collectors as $collector) {
            try {
                $result = $collector->collect($application);
                $facts = [...$facts, ...$result->facts];
                $diagnostics = [...$diagnostics, ...$result->diagnostics];
            } catch (Throwable $exception) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::BootInspectionFailed,
                    'laravel_boot',
                    null,
                    null,
                    null,
                    'A Laravel boot collector failed.',
                    [
                        'stage' => 'collector',
                        'collector' => $collector->name(),
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        if ($symbols === null) {
            return new BootInspectionResult($facts, $diagnostics);
        }

        return new BootInspectionResult(
            array_values(array_filter(
                $facts,
                fn (BootFact $fact): bool => $this->factIsInScope($fact, $symbols, $parsedFiles),
            )),
            array_values(array_filter(
                $diagnostics,
                fn (Diagnostic $diagnostic): bool => $this->diagnosticIsInScope($diagnostic, $symbols),
            )),
        );
    }

    private function factIsInScope(BootFact $fact, SymbolTable $symbols, array $parsedFiles): bool
    {
        return match ($fact->kind) {
            'route' => $this->unique($symbols, $fact->attributes['action_class'] ?? null),
            'container_binding' => $this->unique($symbols, $fact->attributes['abstract'] ?? null)
                && $this->unique($symbols, $fact->attributes['concrete'] ?? null),
            'container_alias' => $this->unique($symbols, $fact->attributes['alias'] ?? null)
                && $this->unique($symbols, $fact->attributes['abstract'] ?? null),
            'event_listener' => $this->unique($symbols, $fact->attributes['event'] ?? null)
                && $this->unique($symbols, $fact->attributes['listener'] ?? null),
            'policy' => $this->unique($symbols, $fact->attributes['model'] ?? null)
                && $this->unique($symbols, $fact->attributes['policy'] ?? null),
            'schedule' => $this->unique($symbols, $fact->attributes['target_class'] ?? null)
                || $this->commandIsInScope(
                    is_string($fact->attributes['command'] ?? null)
                        ? explode(' ', trim($fact->attributes['command']))[0]
                        : null,
                    $parsedFiles,
                ),
            'command' => $this->commandIsInScope($fact->attributes['name'] ?? null, $parsedFiles),
            default => false,
        };
    }

    private function diagnosticIsInScope(Diagnostic $diagnostic, SymbolTable $symbols): bool
    {
        if ($diagnostic->code === DiagnosticCode::BootInspectionFailed) {
            return true;
        }

        if ($diagnostic->code === DiagnosticCode::ClosureContainerBinding) {
            return $this->unique($symbols, $diagnostic->attributes['abstract'] ?? null);
        }

        return false;
    }

    private function unique(SymbolTable $symbols, mixed $qualifiedName): bool
    {
        return is_string($qualifiedName) && count($symbols->exact($qualifiedName)) === 1;
    }

    private function commandIsInScope(mixed $name, array $parsedFiles): bool
    {
        if (! is_string($name)) {
            return false;
        }

        foreach ($parsedFiles as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('property_default') as $fact) {
                if (! in_array($fact->attributes['property'] ?? null, ['signature', 'name'], true)) {
                    continue;
                }

                $value = $fact->attributes['value'] ?? null;

                if (is_string($value) && trim($value, "'\"") === $name) {
                    return true;
                }
            }
        }

        return false;
    }
}
