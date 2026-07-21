<?php

namespace DNDark\LogicMap\Analysis\Laravel\Facts;

use DNDark\LogicMap\Analysis\Facts\BranchConditionFact;
use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class ControlFlowFactMapper
{
    public function map(ParsedFile $file): array
    {
        $branches = array_map(
            static fn ($fact): BranchConditionFact => new BranchConditionFact(
                $fact->file,
                $fact->startLine,
                $fact->endLine,
                $fact->attributes['enclosing_symbol'],
                $fact->attributes['expression'],
                $fact->attributes['branch'],
                $fact->controlContexts,
            ),
            $file->facts('branch_condition'),
        );
        $throws = [];
        $returns = [];

        foreach ($file->facts('terminal') as $terminal) {
            $symbol = $this->enclosingMethod($file, $terminal->startLine);

            if ($symbol === null) {
                continue;
            }

            $guard = $this->guard($branches, $symbol->id->value, $terminal->startLine);
            $expression = $terminal->attributes['expression'];

            if (($terminal->attributes['terminal'] ?? null) === 'throw') {
                $throws[] = new ThrowFact(
                    $terminal->file,
                    $terminal->startLine,
                    $terminal->endLine,
                    $symbol->id->value,
                    $this->exceptionClass($file, $terminal->startLine, $terminal->endLine),
                    $expression,
                    $guard,
                    $terminal->controlContexts,
                );
            } else {
                $returns[] = new EarlyReturnFact(
                    $terminal->file,
                    $terminal->startLine,
                    $terminal->endLine,
                    $symbol->id->value,
                    $expression === 'return' ? null : substr($expression, 7),
                    $guard,
                    $terminal->controlContexts,
                );
            }
        }

        return ['branches' => $branches, 'throws' => $throws, 'early_returns' => $returns];
    }

    private function enclosingMethod(ParsedFile $file, int $line): ?SymbolDefinition
    {
        $methods = array_values(array_filter(
            $file->symbols,
            static fn (SymbolDefinition $symbol): bool => $symbol->structuralKind === NodeKind::Method
                && $symbol->location->startLine <= $line
                && $symbol->location->endLine >= $line,
        ));

        usort($methods, static fn (SymbolDefinition $left, SymbolDefinition $right): int =>
            ($left->location->endLine - $left->location->startLine)
            <=> ($right->location->endLine - $right->location->startLine));

        return $methods[0] ?? null;
    }

    private function guard(array $branches, string $symbol, int $line): ?BranchConditionFact
    {
        $matches = array_values(array_filter(
            $branches,
            static fn (BranchConditionFact $branch): bool => $branch->enclosingSymbol === $symbol
                && $branch->startLine <= $line
                && $branch->endLine >= $line,
        ));
        usort($matches, static fn (BranchConditionFact $left, BranchConditionFact $right): int =>
            ($left->endLine - $left->startLine) <=> ($right->endLine - $right->startLine));

        return $matches[0] ?? null;
    }

    private function exceptionClass(ParsedFile $file, int $startLine, int $endLine): ?string
    {
        foreach ($file->callSites as $call) {
            if ($call->callKind === 'new' && $call->startLine >= $startLine && $call->endLine <= $endLine) {
                return $call->targetName;
            }
        }

        return null;
    }
}
