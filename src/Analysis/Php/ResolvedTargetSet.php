<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use InvalidArgumentException;

final readonly class ResolvedTargetSet
{
    /** @var list<ResolvedTarget> */
    public array $candidates;

    /** @var list<Diagnostic> */
    public array $diagnostics;

    public function __construct(array $candidates = [], array $diagnostics = [])
    {
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof ResolvedTarget) {
                throw new InvalidArgumentException('Resolved target sets require ResolvedTarget candidates.');
            }
        }

        foreach ($diagnostics as $diagnostic) {
            if (! $diagnostic instanceof Diagnostic) {
                throw new InvalidArgumentException('Resolved target sets require Diagnostic values.');
            }
        }

        usort($candidates, static fn (ResolvedTarget $left, ResolvedTarget $right): int => [
            $left->symbol->id->value,
            $left->symbol->location->file,
            $left->symbol->location->startLine,
        ] <=> [
            $right->symbol->id->value,
            $right->symbol->location->file,
            $right->symbol->location->startLine,
        ]);
        $this->candidates = array_values($candidates);
        $this->diagnostics = array_values($diagnostics);
    }
}
