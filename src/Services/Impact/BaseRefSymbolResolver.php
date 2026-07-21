<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
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
use DNDark\LogicMap\Support\Git\GitObjectReader;
use Throwable;

final readonly class BaseRefSymbolResolver
{
    public function __construct(
        private GitObjectReader $objects,
        private PhpFileParser $parser,
        private SymbolTable $activeSymbols,
        private array $activeDiagnostics,
    ) {}

    public function resolve(ChangedFile $file, string $baseCommit, string $headCommit): array
    {
        if ($file->oldPath === null) {
            return ['symbols' => [], 'diagnostics' => []];
        }

        try {
            $source = $this->objects->read($baseCommit, $file->oldPath);
        } catch (Throwable $throwable) {
            return $this->fallback(
                $file,
                $baseCommit,
                $headCommit,
                DiagnosticCode::GitObjectUnreadable,
                'Base Git object could not be read.',
                ['exception' => $throwable::class],
            );
        }

        $parsed = $this->parser->parse($file->oldPath, $source);
        $parseErrors = array_values(array_filter(
            $parsed->diagnostics,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->code === DiagnosticCode::ParseError,
        ));

        if ($parseErrors !== []) {
            return $this->fallback(
                $file,
                $baseCommit,
                $headCommit,
                DiagnosticCode::BaseParseFailed,
                'Base Git object could not be parsed.',
                ['parse_diagnostics' => array_map(static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(), $parseErrors)],
            );
        }

        $baseSymbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $baseSymbols->add($symbol);
        }

        if ($file->changeType === ChangeType::Renamed && $file->hunks === []) {
            $unchanged = $this->unchangedRename(
                $parsed->symbols,
                $file,
                $baseCommit,
                $headCommit,
            );

            if ($unchanged !== []) {
                return ['symbols' => $unchanged, 'diagnostics' => []];
            }
        }

        $hunks = $file->hunks;

        if ($hunks === []) {
            $hunks = [new ChangedHunk(1, max(1, substr_count($source, "\n") + 1), 1, 1)];
        }

        $symbols = [];
        $diagnostics = [];

        foreach ($hunks as $hunk) {
            $oldCandidates = $this->enclosing($baseSymbols, $file->oldPath, $hunk->oldStart, $hunk->oldEnd());

            if (count($oldCandidates) > 1) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::BaseSymbolAmbiguous,
                    'base_ref_symbol_map',
                    $file->oldPath,
                    max(1, $hunk->oldStart),
                    max(1, $hunk->oldEnd()),
                    'Base hunk maps to multiple equally narrow symbols.',
                    ['candidates' => array_map(static fn (SymbolDefinition $symbol): string => $symbol->id->value, $oldCandidates)],
                );
                $symbols[] = $this->fileFallback($file, $hunk, $baseCommit, $headCommit);

                continue;
            }

            if ($oldCandidates === []) {
                $symbols[] = $this->fileFallback($file, $hunk, $baseCommit, $headCommit);

                continue;
            }

            foreach ($oldCandidates as $old) {
                $new = $file->changeType === ChangeType::Renamed
                    ? $this->renamedTarget($old, $file, $hunk)
                    : null;
                $links = $this->diagnosticLinks($old->id);
                $symbols[] = new ChangedSymbol(
                    $file->changeType,
                    $old->id,
                    $new?->id,
                    $file->oldPath,
                    $file->newPath,
                    max(1, $hunk->oldStart),
                    max(1, $hunk->oldEnd()),
                    $file->newPath === null ? null : max(1, $hunk->newStart),
                    $file->newPath === null ? null : max(1, $hunk->newEnd()),
                    $this->evidence($file, $hunk, $baseCommit, $headCommit),
                    $links,
                );
            }
        }

        usort($symbols, static fn (ChangedSymbol $left, ChangedSymbol $right): int => [
            $left->oldNodeId?->value ?? '',
            $left->newNodeId?->value ?? '',
        ] <=> [
            $right->oldNodeId?->value ?? '',
            $right->newNodeId?->value ?? '',
        ]);

        return ['symbols' => $symbols, 'diagnostics' => $diagnostics];
    }

    private function unchangedRename(
        array $baseSymbols,
        ChangedFile $file,
        string $baseCommit,
        string $headCommit,
    ): array {
        $changes = [];

        foreach ($baseSymbols as $old) {
            if (! $old instanceof SymbolDefinition) {
                continue;
            }

            $matches = $this->activeSymbols->byId($old->id);

            if (count($matches) !== 1 || $matches[0]->location->file !== $file->newPath) {
                continue;
            }

            $new = $matches[0];
            $hunk = new ChangedHunk(
                $old->location->startLine,
                $old->location->endLine - $old->location->startLine + 1,
                $new->location->startLine,
                $new->location->endLine - $new->location->startLine + 1,
            );
            $changes[] = new ChangedSymbol(
                ChangeType::Renamed,
                $old->id,
                $new->id,
                $file->oldPath,
                $file->newPath,
                $old->location->startLine,
                $old->location->endLine,
                $new->location->startLine,
                $new->location->endLine,
                $this->evidence($file, $hunk, $baseCommit, $headCommit),
                $this->diagnosticLinks($old->id),
            );
        }

        usort($changes, static fn (ChangedSymbol $left, ChangedSymbol $right): int =>
            ($left->oldNodeId?->value ?? '') <=> ($right->oldNodeId?->value ?? ''));

        return $changes;
    }

    private function enclosing(SymbolTable $symbols, string $path, int $start, int $end): array
    {
        $matches = [];

        for ($line = max(1, $start); $line <= max(1, $end); $line++) {
            foreach ($symbols->smallestEnclosing($path, $line) as $symbol) {
                $key = $symbol->id->value."\0".$symbol->location->startLine;
                $matches[$key] ??= ['symbol' => $symbol, 'overlap' => 0];
                $matches[$key]['overlap']++;
            }
        }

        if ($matches === []) {
            return [];
        }

        $maxOverlap = max(array_column($matches, 'overlap'));

        return array_values(array_map(
            static fn (array $match): SymbolDefinition => $match['symbol'],
            array_filter($matches, static fn (array $match): bool => $match['overlap'] === $maxOverlap),
        ));
    }

    private function renamedTarget(
        SymbolDefinition $old,
        ChangedFile $file,
        ChangedHunk $hunk,
    ): ?SymbolDefinition {
        $same = $this->activeSymbols->byId($old->id);

        if (count($same) === 1) {
            return $same[0];
        }

        if ($file->newPath === null || $hunk->newCount === 0) {
            return null;
        }

        $candidates = $this->enclosing(
            $this->activeSymbols,
            $file->newPath,
            $hunk->newStart,
            $hunk->newEnd(),
        );

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function diagnosticLinks(NodeId $oldId): array
    {
        $callers = [];
        $evidenceIds = [];

        foreach ($this->activeDiagnostics as $diagnostic) {
            if (! $diagnostic instanceof Diagnostic
                || $diagnostic->code !== DiagnosticCode::UnresolvedTarget
                || ($diagnostic->attributes['attempted_target_id'] ?? null) !== $oldId->value) {
                continue;
            }

            if (is_string($diagnostic->attributes['enclosing_symbol_id'] ?? null)) {
                $callers[] = $diagnostic->attributes['enclosing_symbol_id'];
            }

            if (is_string($diagnostic->attributes['call_site_evidence_id'] ?? null)) {
                $evidenceIds[] = $diagnostic->attributes['call_site_evidence_id'];
            }
        }

        $callers = array_values(array_unique($callers));
        $evidenceIds = array_values(array_unique($evidenceIds));
        sort($callers, SORT_STRING);
        sort($evidenceIds, SORT_STRING);

        return [
            'diagnostic_callers' => $callers,
            'diagnostic_evidence_ids' => $evidenceIds,
        ];
    }

    private function fallback(
        ChangedFile $file,
        string $baseCommit,
        string $headCommit,
        DiagnosticCode $code,
        string $message,
        array $attributes,
    ): array {
        $hunk = $file->hunks[0] ?? new ChangedHunk(1, 1, 0, 0);

        return [
            'symbols' => [$this->fileFallback($file, $hunk, $baseCommit, $headCommit)],
            'diagnostics' => [new Diagnostic(
                $code,
                'base_ref_symbol_map',
                $file->oldPath,
                null,
                null,
                $message,
                $attributes,
            )],
        ];
    }

    private function fileFallback(
        ChangedFile $file,
        ChangedHunk $hunk,
        string $baseCommit,
        string $headCommit,
    ): ChangedSymbol {
        return new ChangedSymbol(
            $file->changeType,
            $file->oldPath === null ? null : NodeId::file($file->oldPath),
            $file->newPath === null ? null : NodeId::file($file->newPath),
            $file->oldPath,
            $file->newPath,
            $hunk->oldCount === 0 ? null : max(1, $hunk->oldStart),
            $hunk->oldCount === 0 ? null : max(1, $hunk->oldEnd()),
            $hunk->newCount === 0 ? null : max(1, $hunk->newStart),
            $hunk->newCount === 0 ? null : max(1, $hunk->newEnd()),
            $this->evidence($file, $hunk, $baseCommit, $headCommit),
        );
    }

    private function evidence(
        ChangedFile $file,
        ChangedHunk $hunk,
        string $baseCommit,
        string $headCommit,
    ): EvidenceRecord {
        return new EvidenceRecord(
            EvidenceOrigin::GitDiff,
            'git-diff-symbol-mapper',
            Certainty::Certain,
            new SourceLocation(
                (string) $file->oldPath,
                max(1, $hunk->oldStart),
                max(1, $hunk->oldEnd()),
            ),
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
}
