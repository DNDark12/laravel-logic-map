<?php

namespace DNDark\LogicMap\Analysis\Laravel\Facts;

use DNDark\LogicMap\Analysis\Facts\TransactionBoundaryFact;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class TransactionBoundaryMapper
{
    public function map(ParsedFile $file): array
    {
        $boundaries = [];

        foreach ($file->facts('closure_boundary') as $fact) {
            if (! preg_match('/\bDB::transaction\s*\(/', $fact->attributes['call_expression'])) {
                continue;
            }

            $symbol = $this->enclosingMethod($file, $fact->startLine);

            if ($symbol !== null) {
                $boundaries[] = new TransactionBoundaryFact(
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    $symbol->id->value,
                    'closure',
                    $fact->attributes['body_start_line'] ?? null,
                    $fact->attributes['body_end_line'] ?? null,
                    $fact->controlContexts,
                );
            }
        }

        foreach ($file->callSites as $call) {
            $operation = match ($call->targetName) {
                'beginTransaction' => 'begin',
                'commit' => 'commit',
                'rollBack' => 'rollback',
                default => null,
            };

            if ($operation === null || $call->receiverType !== 'Illuminate\Support\Facades\DB') {
                continue;
            }

            $symbol = $this->enclosingMethod($file, $call->startLine);

            if ($symbol !== null) {
                $boundaries[] = new TransactionBoundaryFact(
                    $call->file,
                    $call->startLine,
                    $call->endLine,
                    $symbol->id->value,
                    $operation,
                    null,
                    null,
                    $call->controlContexts,
                );
            }
        }

        usort($boundaries, static fn (TransactionBoundaryFact $left, TransactionBoundaryFact $right): int => [
            $left->startLine,
            $left->endLine,
            $left->operation,
        ] <=> [
            $right->startLine,
            $right->endLine,
            $right->operation,
        ]);

        return $boundaries;
    }

    private function enclosingMethod(ParsedFile $file, int $line): ?SymbolDefinition
    {
        foreach ($file->symbols as $symbol) {
            if ($symbol->structuralKind === NodeKind::Method
                && $symbol->location->startLine <= $line
                && $symbol->location->endLine >= $line) {
                return $symbol;
            }
        }

        return null;
    }
}
