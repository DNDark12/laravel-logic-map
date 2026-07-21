<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;

final class CallTargetLocator
{
    public function instantiatedArgument(
        ParsedFile $file,
        CallSiteFact $outerCall,
        SymbolTable $symbols,
    ): ?SymbolDefinition {
        $candidates = [];

        foreach ($file->callSites as $call) {
            if ($call->callKind !== 'new'
                || ! $call->enclosingSymbolId->equals($outerCall->enclosingSymbolId)
                || $call->startLine < $outerCall->startLine
                || $call->endLine > $outerCall->endLine
                || ! str_contains($outerCall->normalizedExpression, $call->normalizedExpression)) {
                continue;
            }

            $targets = $symbols->exact($call->targetName);

            if (count($targets) === 1) {
                $candidates[$targets[0]->id->value] = $targets[0];
            }
        }

        return count($candidates) === 1 ? array_values($candidates)[0] : null;
    }

    public function classConstant(mixed $argument): ?string
    {
        if (! is_array($argument) || ! is_string($argument['class_constant'] ?? null)) {
            return null;
        }

        $value = $argument['class_constant'];

        return str_ends_with($value, '::class') ? substr($value, 0, -7) : null;
    }

    public function isQueueable(SymbolDefinition $class): bool
    {
        return in_array(
            'Illuminate\Contracts\Queue\ShouldQueue',
            $class->attributes['implements'] ?? [],
            true,
        );
    }
}
