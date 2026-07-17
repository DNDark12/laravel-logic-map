<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class AuthorizationDetector
{
    public function detect(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        $policies = $this->policies($files, $bootFacts);
        $diagnostics = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->callSites as $call) {
                if (! $this->isAuthorizationCall($call)) {
                    continue;
                }

                $sourceMethods = $symbols->byId($call->enclosingSymbolId);

                if (count($sourceMethods) !== 1) {
                    $diagnostics[] = $this->dynamicAbilityDiagnostic($call);

                    continue;
                }

                if ($call->targetName === 'authorizeResource') {
                    $model = $this->classConstantArgument($call->arguments[0] ?? null);

                    if ($model === null) {
                        $diagnostics[] = $this->dynamicAbilityDiagnostic($call);

                        continue;
                    }

                    foreach ($policies as $policy) {
                        if ($policy['model'] === $model) {
                            $this->emitResource($call, $sourceMethods[0], $model, $policy, $symbols, $graph);
                        }
                    }

                    continue;
                }

                $ability = $call->arguments[0] ?? null;

                if (! is_string($ability)) {
                    $diagnostics[] = $this->dynamicAbilityDiagnostic($call);

                    continue;
                }

                $model = $this->modelType($call->arguments[1] ?? null, $sourceMethods[0]);

                if ($model === null) {
                    $diagnostics[] = $this->dynamicAbilityDiagnostic($call);

                    continue;
                }

                foreach ($policies as $policy) {
                    if ($policy['model'] !== $model) {
                        continue;
                    }

                    $this->emit(
                        $call,
                        $sourceMethods[0],
                        $ability,
                        $model,
                        $policy,
                        $symbols,
                        $graph,
                    );
                }
            }
        }

        foreach ($this->routeCanObservations($files, $bootFacts) as $observation) {
            $sourceMethods = $symbols->methods(
                $observation['action_class'],
                $observation['action_method'],
            );

            if (count($sourceMethods) !== 1) {
                continue;
            }

            foreach ($observation['middleware'] as $middleware) {
                $authorization = $this->parseCanMiddleware($middleware);

                if ($authorization === null) {
                    continue;
                }

                $model = $sourceMethods[0]->declaredParameterTypes[$authorization['parameter']] ?? null;

                if (! is_string($model)) {
                    continue;
                }

                foreach ($policies as $policy) {
                    if ($policy['origin'] === $observation['origin'] && $policy['model'] === $model) {
                        $this->emitRouteAuthorization(
                            $sourceMethods[0],
                            $authorization['ability'],
                            $model,
                            $policy,
                            $observation,
                            $symbols,
                            $graph,
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }

    private function policies(array $files, array $bootFacts): array
    {
        $policies = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_policy_registration') as $fact) {
                if (($fact->attributes['dynamic'] ?? true) === true) {
                    continue;
                }

                $policies[] = [
                    'model' => $fact->attributes['model'],
                    'policy' => $fact->attributes['policy'],
                    'origin' => EvidenceOrigin::StaticAst,
                    'registration_key' => $fact->attributes['registration_key'],
                    'registration_file' => $fact->file,
                    'registration_line' => $fact->startLine,
                ];
            }
        }

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'policy') {
                continue;
            }

            $policies[] = [
                'model' => $fact->attributes['model'],
                'policy' => $fact->attributes['policy'],
                'origin' => EvidenceOrigin::LaravelBoot,
                'registration_key' => 'policy:'.$fact->attributes['model'].'=>'.$fact->attributes['policy'],
                'registration_file' => null,
                'registration_line' => null,
            ];
        }

        return $policies;
    }

    private function emit(
        CallSiteFact $call,
        SymbolDefinition $source,
        string $ability,
        string $model,
        array $policy,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $policyMethods = $symbols->methods($policy['policy'], $ability);
        $policyClasses = $symbols->exact($policy['policy']);

        if (count($policyMethods) === 1) {
            $target = $policyMethods[0]->id;
        } elseif (count($policyClasses) === 1) {
            $target = $policyClasses[0]->id;
        } else {
            return;
        }

        $static = $policy['origin'] === EvidenceOrigin::StaticAst;
        $registrationKey = $static
            ? $policy['registration_key']
            : $policy['registration_key'].':authorization:'.$call->file.':'.$call->startLine.':'.$call->endLine;
        SemanticEdgeFactory::add(
            $graph,
            $source->id,
            EdgeType::AuthorizesWith,
            $target,
            $policy['origin'],
            'authorization_detector',
            Certainty::Certain,
            $static ? new SourceLocation($call->file, $call->startLine, $call->endLine) : null,
            $static ? $call->normalizedExpression : null,
            $registrationKey,
            'policy:'.$model.'=>'.$policy['policy'].':ability:'.$ability,
            [
                'ability' => $ability,
                'model' => $model,
                'policy' => $policy['policy'],
                'policy_registration_file' => $policy['registration_file'],
                'policy_registration_line' => $policy['registration_line'],
                'effective' => ! $static,
            ],
        );
    }

    private function isAuthorizationCall(CallSiteFact $call): bool
    {
        if (! in_array($call->targetName, ['authorize', 'allows', 'authorizeResource'], true)) {
            return false;
        }

        return $call->receiverType === 'Illuminate\Support\Facades\Gate'
            || $call->receiverExpression === '$this';
    }

    private function emitResource(
        CallSiteFact $call,
        SymbolDefinition $source,
        string $model,
        array $policy,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $targets = $symbols->exact($policy['policy']);

        if (count($targets) !== 1) {
            return;
        }

        $static = $policy['origin'] === EvidenceOrigin::StaticAst;
        SemanticEdgeFactory::add(
            $graph,
            $source->id,
            EdgeType::AuthorizesWith,
            $targets[0]->id,
            $policy['origin'],
            'authorization_detector',
            Certainty::Certain,
            $static ? new SourceLocation($call->file, $call->startLine, $call->endLine) : null,
            $static ? $call->normalizedExpression : null,
            $static
                ? $policy['registration_key']
                : $policy['registration_key'].':resource:'.$call->file.':'.$call->startLine,
            'policy:'.$model.'=>'.$policy['policy'].':ability:resource',
            [
                'ability' => 'resource',
                'model' => $model,
                'policy' => $policy['policy'],
                'resource_registration' => true,
                'effective' => ! $static,
            ],
        );
    }

    private function emitRouteAuthorization(
        SymbolDefinition $source,
        string $ability,
        string $model,
        array $policy,
        array $observation,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $policyMethods = $symbols->methods($policy['policy'], $ability);
        $policyClasses = $symbols->exact($policy['policy']);
        $target = count($policyMethods) === 1
            ? $policyMethods[0]->id
            : (count($policyClasses) === 1 ? $policyClasses[0]->id : null);

        if ($target === null) {
            return;
        }

        SemanticEdgeFactory::add(
            $graph,
            $source->id,
            EdgeType::AuthorizesWith,
            $target,
            $observation['origin'],
            'authorization_detector',
            Certainty::Certain,
            $observation['location'],
            $observation['expression'],
            $observation['registration_key'].':can:'.$ability,
            'policy:'.$model.'=>'.$policy['policy'].':ability:'.$ability,
            [
                'ability' => $ability,
                'model' => $model,
                'policy' => $policy['policy'],
                'route_middleware' => true,
                'effective' => $observation['origin'] === EvidenceOrigin::LaravelBoot,
            ],
        );
    }

    private function routeCanObservations(array $files, array $bootFacts): array
    {
        $static = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_route_registration') as $fact) {
                if (($fact->attributes['dynamic'] ?? true) === true) {
                    continue;
                }

                $static[$fact->attributes['registration_key']] = [
                    'origin' => EvidenceOrigin::StaticAst,
                    'action_class' => $fact->attributes['action_class'],
                    'action_method' => $fact->attributes['action_method'],
                    'middleware' => [],
                    'registration_key' => $fact->attributes['registration_key'],
                    'location' => new SourceLocation($fact->file, $fact->startLine, $fact->endLine),
                    'expression' => $fact->attributes['expression'] ?? null,
                ];
            }
        }

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_route_chain') as $fact) {
                $key = $fact->attributes['registration_key'] ?? null;

                if (isset($static[$key])
                    && ($fact->attributes['operation'] ?? null) === 'middleware'
                    && is_array($fact->attributes['middleware'] ?? null)) {
                    $static[$key]['middleware'] = [
                        ...$static[$key]['middleware'],
                        ...$fact->attributes['middleware'],
                    ];
                }
            }
        }

        $observations = array_values($static);

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'route') {
                continue;
            }

            $observations[] = [
                'origin' => EvidenceOrigin::LaravelBoot,
                'action_class' => $fact->attributes['action_class'],
                'action_method' => $fact->attributes['action_method'],
                'middleware' => $fact->attributes['middleware'] ?? [],
                'registration_key' => 'route:'.implode(',', $fact->attributes['methods'] ?? [])
                    .':'.$fact->attributes['uri'],
                'location' => null,
                'expression' => null,
            ];
        }

        return $observations;
    }

    private function parseCanMiddleware(mixed $middleware): ?array
    {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
            return null;
        }

        $parts = explode(',', substr($middleware, 4));

        if (count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }

        return ['ability' => trim($parts[0]), 'parameter' => trim($parts[1])];
    }

    private function classConstantArgument(mixed $argument): ?string
    {
        if (! is_array($argument) || ! is_string($argument['class_constant'] ?? null)) {
            return null;
        }

        $value = $argument['class_constant'];

        return str_ends_with($value, '::class') ? substr($value, 0, -7) : null;
    }

    private function modelType(mixed $argument, SymbolDefinition $method): ?string
    {
        if (! is_array($argument) || ! is_string($argument['expression'] ?? null)) {
            return null;
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $argument['expression'], $matches) !== 1) {
            return null;
        }

        return $method->declaredParameterTypes[$matches[1]] ?? null;
    }

    private function dynamicAbilityDiagnostic(CallSiteFact $call): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::DynamicClassString,
            'laravel_semantics',
            $call->file,
            $call->startLine,
            $call->endLine,
            'Authorization ability or subject type could not be resolved.',
            ['expression' => $call->normalizedExpression],
        );
    }
}
