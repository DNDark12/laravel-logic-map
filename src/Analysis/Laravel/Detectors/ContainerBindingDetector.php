<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

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

final class ContainerBindingDetector
{
    public function detect(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        $diagnostics = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_container_binding') as $fact) {
                if (($fact->attributes['dynamic'] ?? true) === true) {
                    $diagnostics[] = $this->dynamicDiagnostic($fact);

                    continue;
                }

                $this->emitBinding(
                    $fact->attributes['abstract'],
                    $fact->attributes['concrete'],
                    (bool) ($fact->attributes['shared'] ?? false),
                    EvidenceOrigin::StaticAst,
                    new SourceLocation($fact->file, $fact->startLine, $fact->endLine),
                    $fact->attributes['expression'] ?? null,
                    $fact->attributes['registration_key'],
                    ($fact->attributes['closure'] ?? false) === true
                        ? Certainty::Possible
                        : Certainty::Certain,
                    $symbols,
                    $graph,
                );
            }
        }

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'container_binding') {
                continue;
            }

            $abstract = $fact->attributes['abstract'] ?? null;
            $concrete = $fact->attributes['concrete'] ?? null;

            if (! is_string($abstract) || ! is_string($concrete)) {
                continue;
            }

            $this->emitBinding(
                $abstract,
                $concrete,
                (bool) ($fact->attributes['shared'] ?? false),
                EvidenceOrigin::LaravelBoot,
                null,
                null,
                'binding:'.$abstract.'=>'.$concrete,
                Certainty::Certain,
                $symbols,
                $graph,
            );
        }

        $this->emitInjections($symbols, $graph);

        return $diagnostics;
    }

    private function emitBinding(
        string $abstract,
        string $concrete,
        bool $shared,
        EvidenceOrigin $origin,
        ?SourceLocation $location,
        ?string $expression,
        string $registrationKey,
        Certainty $certainty,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $abstractSymbols = $symbols->exact($abstract);
        $concreteSymbols = $symbols->exact($concrete);

        if (count($abstractSymbols) !== 1 || count($concreteSymbols) !== 1) {
            return;
        }

        $identity = 'binding:'.$abstract.'=>'.$concrete;
        SemanticEdgeFactory::add(
            $graph,
            $abstractSymbols[0]->id,
            EdgeType::BindsTo,
            $concreteSymbols[0]->id,
            $origin,
            'container_binding_detector',
            $certainty,
            $location,
            $expression,
            $registrationKey,
            $identity,
            ['shared' => $shared, 'effective' => $origin === EvidenceOrigin::LaravelBoot],
        );

        foreach ($this->ownedMethods($symbols, $abstractSymbols[0]) as $abstractMethod) {
            $concreteMethods = $symbols->methods($concrete, $abstractMethod->name);

            if (count($concreteMethods) !== 1) {
                continue;
            }

            SemanticEdgeFactory::add(
                $graph,
                $abstractMethod->id,
                EdgeType::ResolvesTo,
                $concreteMethods[0]->id,
                $origin,
                'container_binding_detector',
                $certainty,
                $location,
                $expression,
                $registrationKey.':method:'.$abstractMethod->name,
                $identity.':method:'.$abstractMethod->name,
                ['binding' => $identity, 'effective' => $origin === EvidenceOrigin::LaravelBoot],
            );
        }
    }

    private function emitInjections(SymbolTable $symbols, KnowledgeGraph $graph): void
    {
        foreach ($symbols->all() as $method) {
            if ($method->structuralKind !== NodeKind::Method) {
                continue;
            }

            foreach ($method->declaredParameterTypes as $parameter => $type) {
                $targets = $symbols->exact(ltrim($type, '?'));

                if (count($targets) !== 1) {
                    continue;
                }

                SemanticEdgeFactory::add(
                    $graph,
                    $method->id,
                    EdgeType::Injects,
                    $targets[0]->id,
                    EvidenceOrigin::StaticAst,
                    'dependency_injection_detector',
                    Certainty::Certain,
                    $method->location,
                    '$'.$parameter.':'.$type,
                    null,
                    null,
                    ['parameter' => $parameter, 'declared_type' => $type],
                );
            }
        }
    }

    private function ownedMethods(SymbolTable $symbols, SymbolDefinition $owner): array
    {
        return array_values(array_filter(
            $symbols->all(),
            static fn (SymbolDefinition $symbol): bool => $symbol->structuralKind === NodeKind::Method
                && ($symbol->attributes['owner_id'] ?? null) === $owner->id->value,
        ));
    }

    private function dynamicDiagnostic(SemanticFact $fact): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::DynamicClassString,
            'laravel_semantics',
            $fact->file,
            $fact->startLine,
            $fact->endLine,
            'Container binding uses a dynamic abstract or concrete.',
            ['registration_key' => $fact->attributes['registration_key'] ?? null],
        );
    }
}
