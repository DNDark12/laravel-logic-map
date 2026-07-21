<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangedHunk;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use InvalidArgumentException;

final readonly class ChangedSymbolMapper
{
    public function __construct(private SymbolTable $symbols) {}

    public function map(array $files, string $baseCommit, string $headCommit): array
    {
        $this->assertCommit($baseCommit);
        $this->assertCommit($headCommit);
        $changes = [];
        $diagnostics = [];

        foreach ($files as $file) {
            if (! $file instanceof ChangedFile) {
                throw new InvalidArgumentException('ChangedSymbolMapper requires ChangedFile values.');
            }

            if ($file->newPath === null || $file->changeType === ChangeType::Deleted) {
                continue;
            }

            $hunks = $file->hunks === [] ? [new ChangedHunk(0, 0, 1, 1)] : $file->hunks;

            foreach ($hunks as $hunk) {
                if ($hunk->newCount === 0) {
                    continue;
                }

                $mapped = [];
                $ambiguous = false;

                for ($line = max(1, $hunk->newStart); $line <= max(1, $hunk->newEnd()); $line++) {
                    $candidates = $this->symbols->smallestEnclosing($file->newPath, $line);

                    if (count($candidates) > 1) {
                        $ambiguous = true;
                        $diagnostics[] = $this->ambiguity($file, $hunk, $candidates);
                        break;
                    }

                    if (count($candidates) === 1) {
                        $mapped[$candidates[0]->id->value] = $candidates[0]->id;
                    }
                }

                if ($ambiguous || $mapped === []) {
                    $mapped = [NodeId::file($file->newPath)->value => NodeId::file($file->newPath)];
                }

                foreach ($mapped as $nodeId) {
                    $changes[] = new ChangedSymbol(
                        $file->changeType,
                        null,
                        $nodeId,
                        $file->oldPath,
                        $file->newPath,
                        $hunk->oldCount === 0 ? null : max(1, $hunk->oldStart),
                        $hunk->oldCount === 0 ? null : max(1, $hunk->oldEnd()),
                        max(1, $hunk->newStart),
                        max(1, $hunk->newEnd()),
                        $this->evidence($file, $hunk, $baseCommit, $headCommit, false),
                    );
                }
            }
        }

        usort($changes, static fn (ChangedSymbol $left, ChangedSymbol $right): int => [
            $left->newNodeId?->value ?? '',
            $left->newStartLine ?? 0,
        ] <=> [
            $right->newNodeId?->value ?? '',
            $right->newStartLine ?? 0,
        ]);

        return ['symbols' => $changes, 'diagnostics' => $diagnostics];
    }

    private function evidence(
        ChangedFile $file,
        ChangedHunk $hunk,
        string $baseCommit,
        string $headCommit,
        bool $oldSide,
    ): EvidenceRecord {
        $path = $oldSide ? $file->oldPath : $file->newPath;
        $start = $oldSide ? $hunk->oldStart : $hunk->newStart;
        $end = $oldSide ? $hunk->oldEnd() : $hunk->newEnd();

        return new EvidenceRecord(
            EvidenceOrigin::GitDiff,
            'git-diff-symbol-mapper',
            Certainty::Certain,
            new SourceLocation((string) $path, max(1, $start), max(1, $end)),
            null,
            null,
            [
                'change_type' => $file->changeType->value,
                'old_path' => $file->oldPath,
                'new_path' => $file->newPath,
                'old_span' => [$hunk->oldStart, $hunk->oldEnd()],
                'new_span' => [$hunk->newStart, $hunk->newEnd()],
                'base_commit' => $baseCommit,
                'head_commit' => $headCommit,
            ],
        );
    }

    private function ambiguity(ChangedFile $file, ChangedHunk $hunk, array $candidates): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::DuplicateSymbol,
            'git_diff_symbol_map',
            $file->newPath,
            max(1, $hunk->newStart),
            max(1, $hunk->newEnd()),
            'Changed hunk maps to multiple equally narrow active symbols.',
            ['candidates' => array_map(
                static fn (SymbolDefinition $symbol): string => $symbol->id->value,
                $candidates,
            )],
        );
    }

    private function assertCommit(string $commit): void
    {
        if (preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new InvalidArgumentException('Changed symbol mapping requires validated commit IDs.');
        }
    }
}
