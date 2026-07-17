<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final readonly class CallTargetResolver
{
    public function __construct(private SymbolTable $symbols)
    {
    }

    /**
     * @param array<string, string> $imports
     * @param list<SemanticFact> $facts
     */
    public function resolve(
        CallSiteFact $call,
        array $imports = [],
        array $facts = [],
        ?string $receiverTypeOverride = null,
    ): ResolvedTargetSet {
        $receiverType = $this->normalizeReceiverType($receiverTypeOverride ?? $call->receiverType);

        if ($receiverType === null) {
            $receiverType = $this->candidateTypeFromFacts($call, $facts);
        }

        if ($receiverType === null) {
            return new ResolvedTargetSet([], [$this->diagnostic(
                DiagnosticCode::UnresolvedReceiver,
                $call,
                'Receiver type could not be resolved.',
                ['receiver_expression' => $call->receiverExpression],
            )]);
        }

        $receiverType = $imports[$receiverType] ?? $receiverType;
        $receiverSymbols = $this->symbols->exact($receiverType);

        if ($receiverSymbols === []) {
            $fallback = array_values(array_filter(
                $this->symbols->all(),
                static fn (SymbolDefinition $symbol): bool => $symbol->qualifiedName !== null
                    && ! str_contains($symbol->qualifiedName, '::')
                    && ($symbol->qualifiedName === $receiverType
                        || str_ends_with($symbol->qualifiedName, '\\'.$receiverType)),
            ));
            $qualifiedNames = array_values(array_unique(array_map(
                static fn (SymbolDefinition $symbol): ?string => $symbol->qualifiedName,
                $fallback,
            )));

            if (count($qualifiedNames) === 1) {
                $receiverType = $qualifiedNames[0];
                $receiverSymbols = $this->symbols->exact($receiverType);
            } elseif (count($qualifiedNames) > 1) {
                return new ResolvedTargetSet([], [$this->diagnostic(
                    DiagnosticCode::AmbiguousTarget,
                    $call,
                    "Receiver {$receiverType} matches multiple qualified symbols.",
                    ['candidates' => $qualifiedNames],
                )]);
            }
        }

        if ($this->containsDuplicateDeclarations($receiverSymbols)) {
            return $this->ambiguous($call, $receiverSymbols, 'receiver_declaration');
        }

        $receiver = $receiverSymbols[0] ?? null;

        if ($receiver?->structuralKind === NodeKind::InterfaceSymbol) {
            return $this->resolveInterface($call, $receiverType);
        }

        $exact = $this->symbols->methods($receiverType, $call->targetName);

        if (count($exact) === 1) {
            return new ResolvedTargetSet([
                new ResolvedTarget($exact[0], Certainty::Certain, 'exact_receiver_type', [
                    'receiver_type' => $receiverType,
                ]),
            ]);
        }

        if (count($exact) > 1) {
            return $this->ambiguous($call, $exact, 'duplicate_method');
        }

        $interface = $this->resolveInterface($call, $receiverType);

        if ($interface->candidates !== [] || $interface->diagnostics !== []) {
            return $interface;
        }

        if ($receiver !== null) {
            $inherited = $this->resolveInherited($call, $receiver, []);

            if ($inherited->candidates !== [] || $inherited->diagnostics !== []) {
                return $inherited;
            }
        }

        if ($receiver !== null) {
            $attempted = NodeId::method($receiverType, $call->targetName);
            $evidence = new EvidenceRecord(
                EvidenceOrigin::StaticAst,
                'call-target-resolver',
                Certainty::Certain,
                new SourceLocation($call->file, $call->startLine, $call->endLine),
                $call->normalizedExpression,
                null,
                [
                    'receiver_expression' => $call->receiverExpression,
                    'receiver_type' => $receiverType,
                    'arguments' => $call->arguments,
                    'nullsafe' => (bool) ($call->attributes['nullsafe'] ?? false),
                    'first_class_callable' => (bool) ($call->attributes['first_class_callable'] ?? false),
                ],
            );

            return new ResolvedTargetSet([], [$this->diagnostic(
                DiagnosticCode::UnresolvedTarget,
                $call,
                "Known call target {$attempted->value} is missing.",
                [
                    'receiver_type' => $receiverType,
                    'target_name' => $call->targetName,
                    'attempted_target_id' => $attempted->value,
                    'call_site_evidence_id' => $evidence->id(),
                ],
            )]);
        }

        return new ResolvedTargetSet([], [$this->diagnostic(
            DiagnosticCode::AmbiguousTarget,
            $call,
            "No canonical target {$receiverType}::{$call->targetName} was found.",
            ['receiver_type' => $receiverType, 'target_name' => $call->targetName],
        )]);
    }

    private function resolveInterface(CallSiteFact $call, string $interface): ResolvedTargetSet
    {
        $implementations = $this->symbols->implementations($interface);

        if ($implementations === []) {
            return new ResolvedTargetSet();
        }

        $candidates = [];

        foreach ($implementations as $implementation) {
            $methods = $this->symbols->methods((string) $implementation->qualifiedName, $call->targetName);

            if (count($methods) > 1) {
                return $this->ambiguous($call, $methods, 'duplicate_implementation_method');
            }

            if (count($methods) === 1) {
                $candidates[] = new ResolvedTarget(
                    $methods[0],
                    Certainty::Probable,
                    'interface_implementation',
                    [
                        'interface' => $interface,
                        'implementation' => $implementation->qualifiedName,
                        'implementation_count' => count($implementations),
                    ],
                );
            }
        }

        return new ResolvedTargetSet($candidates);
    }

    /** @param array<string, true> $visited */
    private function resolveInherited(
        CallSiteFact $call,
        SymbolDefinition $receiver,
        array $visited,
    ): ResolvedTargetSet {
        if ($receiver->qualifiedName === null || isset($visited[$receiver->qualifiedName])) {
            return new ResolvedTargetSet();
        }

        $visited[$receiver->qualifiedName] = true;

        foreach ($receiver->attributes['uses_traits'] ?? [] as $trait) {
            $methods = $this->symbols->methods($trait, $call->targetName);

            if (count($methods) > 1) {
                return $this->ambiguous($call, $methods, 'duplicate_trait_method');
            }

            if (count($methods) === 1) {
                return new ResolvedTargetSet([new ResolvedTarget(
                    $methods[0],
                    Certainty::Certain,
                    'trait_method',
                    ['receiver_class' => $receiver->qualifiedName, 'trait' => $trait],
                )]);
            }
        }

        foreach ($receiver->attributes['extends'] ?? [] as $parent) {
            $methods = $this->symbols->methods($parent, $call->targetName);

            if (count($methods) > 1) {
                return $this->ambiguous($call, $methods, 'duplicate_inherited_method');
            }

            if (count($methods) === 1) {
                return new ResolvedTargetSet([new ResolvedTarget(
                    $methods[0],
                    Certainty::Certain,
                    'inherited_method',
                    ['receiver_class' => $receiver->qualifiedName, 'declaring_class' => $parent],
                )]);
            }

            $parents = $this->symbols->exact($parent);

            if (count($parents) === 1) {
                $resolved = $this->resolveInherited($call, $parents[0], $visited);

                if ($resolved->candidates !== [] || $resolved->diagnostics !== []) {
                    return $resolved;
                }
            }
        }

        return new ResolvedTargetSet();
    }

    /** @param list<SemanticFact> $facts */
    private function candidateTypeFromFacts(CallSiteFact $call, array $facts): ?string
    {
        $candidates = $call->attributes['receiver_candidates'] ?? [];

        foreach ($facts as $fact) {
            if (
                $fact->kind === 'container_candidate'
                && ($fact->attributes['receiver_expression'] ?? null) === $call->receiverExpression
                && is_string($fact->attributes['type'] ?? null)
            ) {
                $candidates[] = $fact->attributes['type'];
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, 'is_string')));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function normalizeReceiverType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $type = trim($type);

        if (str_starts_with($type, '?')) {
            $type = substr($type, 1);
        }

        if (str_contains($type, '|')) {
            $parts = array_values(array_filter(
                explode('|', $type),
                static fn (string $part): bool => strtolower(trim($part, " ()")) !== 'null',
            ));

            if (count($parts) !== 1) {
                return null;
            }

            $type = trim($parts[0], " ()");
        }

        return ltrim($type, '\\');
    }

    /** @param list<SymbolDefinition> $symbols */
    private function containsDuplicateDeclarations(array $symbols): bool
    {
        if (count($symbols) < 2) {
            return false;
        }

        return count(array_unique(array_map(
            static fn (SymbolDefinition $symbol): string => $symbol->id->value,
            $symbols,
        ))) !== count($symbols);
    }

    /** @param list<SymbolDefinition> $symbols */
    private function ambiguous(CallSiteFact $call, array $symbols, string $reason): ResolvedTargetSet
    {
        return new ResolvedTargetSet([], [$this->diagnostic(
            DiagnosticCode::AmbiguousTarget,
            $call,
            "Call target is ambiguous ({$reason}).",
            [
                'reason' => $reason,
                'candidates' => array_map(
                    static fn (SymbolDefinition $symbol): string => $symbol->id->value,
                    $symbols,
                ),
            ],
        )]);
    }

    private function diagnostic(
        DiagnosticCode $code,
        CallSiteFact $call,
        string $message,
        array $attributes,
    ): Diagnostic {
        return new Diagnostic(
            $code,
            'resolve',
            $call->file,
            $call->startLine,
            $call->endLine,
            $message,
            $attributes + [
                'enclosing_symbol_id' => $call->enclosingSymbolId->value,
                'expression' => $call->normalizedExpression,
                'nullsafe' => (bool) ($call->attributes['nullsafe'] ?? false),
                'first_class_callable' => (bool) ($call->attributes['first_class_callable'] ?? false),
            ],
        );
    }
}
