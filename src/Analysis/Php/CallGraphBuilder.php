<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;

final readonly class CallGraphBuilder
{
    public function __construct(
        private CallTargetResolver $resolver,
        private SymbolTable $symbols,
    ) {
    }

    /**
     * @param list<ParsedFile> $files
     * @return list<Diagnostic>
     */
    public function build(array $files, KnowledgeGraph $graph): array
    {
        $diagnostics = [];

        foreach ($files as $file) {
            $returnTypes = [];
            $calls = $file->callSites;
            usort($calls, static fn (CallSiteFact $left, CallSiteFact $right): int => [
                $left->startLine,
                strlen($left->normalizedExpression),
                $left->normalizedExpression,
            ] <=> [
                $right->startLine,
                strlen($right->normalizedExpression),
                $right->normalizedExpression,
            ]);

            foreach ($calls as $call) {
                if (! $graph->hasNode($call->enclosingSymbolId)) {
                    continue;
                }

                if ($call->callKind === 'function') {
                    continue;
                }

                if ($call->callKind === 'new') {
                    $this->buildInstantiation($call, $graph);

                    continue;
                }

                $override = $call->receiverExpression === null
                    ? null
                    : ($returnTypes[$call->receiverExpression] ?? null);
                $resolved = $this->resolver->resolve(
                    $call,
                    $file->imports,
                    $file->semanticFacts,
                    $override,
                );
                $diagnostics = [...$diagnostics, ...$resolved->diagnostics];

                foreach ($resolved->candidates as $candidate) {
                    if (! $graph->hasNode($candidate->symbol->id)) {
                        continue;
                    }

                    $graph->addEdge(GraphEdge::fromEvidence(
                        $call->enclosingSymbolId,
                        $candidate->symbol->id,
                        EdgeType::Calls,
                        new EvidenceRecord(
                            EvidenceOrigin::StaticAst,
                            'call-target-resolver',
                            $candidate->certainty,
                            new SourceLocation($call->file, $call->startLine, $call->endLine),
                            $call->normalizedExpression,
                            null,
                            [
                                'receiver_resolution' => $candidate->reason,
                                'receiver_expression' => $call->receiverExpression,
                                'receiver_type' => $call->receiverType,
                                'arguments' => $call->arguments,
                                'nullsafe' => (bool) ($call->attributes['nullsafe'] ?? false),
                                'first_class_callable' => (bool) ($call->attributes['first_class_callable'] ?? false),
                                ...$candidate->evidence,
                            ],
                        ),
                    ));
                }

                $returnType = $this->commonReturnType($resolved);

                if ($returnType !== null) {
                    $returnTypes[$call->normalizedExpression] = $returnType;
                }
            }
        }

        return $diagnostics;
    }

    private function buildInstantiation(CallSiteFact $call, KnowledgeGraph $graph): void
    {
        $target = ltrim((string) ($call->receiverType ?? $call->targetName), '\\');
        $targetId = null;

        if (str_contains($target, '@anonymous[')) {
            $candidate = NodeId::fromString('class:'.$target);
            $targetId = $graph->hasNode($candidate) ? $candidate : null;
        } else {
            $symbols = $this->symbols->exact($target);

            if (count($symbols) === 1) {
                $targetId = $symbols[0]->id;
            }
        }

        if ($targetId === null || ! $graph->hasNode($targetId)) {
            return;
        }

        $graph->addEdge(GraphEdge::fromEvidence(
            $call->enclosingSymbolId,
            $targetId,
            EdgeType::Instantiates,
            new EvidenceRecord(
                EvidenceOrigin::StaticAst,
                'instantiation-resolver',
                Certainty::Certain,
                new SourceLocation($call->file, $call->startLine, $call->endLine),
                $call->normalizedExpression,
                null,
                ['arguments' => $call->arguments],
            ),
        ));
    }

    private function commonReturnType(ResolvedTargetSet $targets): ?string
    {
        $types = [];

        foreach ($targets->candidates as $candidate) {
            $type = $candidate->symbol->declaredReturnType;

            if ($type === null || $type === 'void' || $type === 'never') {
                continue;
            }

            if ($type === 'self' || $type === 'static') {
                $type = strstr((string) $candidate->symbol->qualifiedName, '::', true) ?: null;
            } elseif ($type === 'parent') {
                $owner = strstr((string) $candidate->symbol->qualifiedName, '::', true);
                $ownerSymbol = $owner === false ? [] : $this->symbols->exact($owner);
                $type = count($ownerSymbol) === 1
                    ? ($ownerSymbol[0]->attributes['extends'][0] ?? null)
                    : null;
            }

            if (is_string($type)) {
                $types[] = ltrim($type, '?\\');
            }
        }

        $types = array_values(array_unique($types));

        return count($types) === 1 ? $types[0] : null;
    }
}
