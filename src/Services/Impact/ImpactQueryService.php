<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;
use DNDark\LogicMap\Support\Git\GitDiffChangeProvider;
use DNDark\LogicMap\Support\Git\GitObjectReader;
use DNDark\LogicMap\Support\Git\NativeGitCommandRunner;
use InvalidArgumentException;

final readonly class ImpactQueryService
{
    public function __construct(
        private string $repositoryRoot,
        private PhpFileParser $parser,
        private int $maxNodes,
        private int $maxEdges,
        private int $maxDepth,
        private int $maxResponseBytes,
        private int $gitTimeoutMs = 10_000,
    ) {
        if (! is_dir($repositoryRoot)
            || min($maxNodes, $maxEdges, $maxDepth, $maxResponseBytes, $gitTimeoutMs) < 1) {
            throw new InvalidArgumentException('Impact queries require a repository and positive bounded limits.');
        }
    }

    public function analyze(
        GraphSnapshot $snapshot,
        ?string $symbol = null,
        ?string $base = null,
        ?string $head = null,
        array $relationOverlays = [],
    ): \DNDark\LogicMap\Domain\Impact\ImpactReport {
        $symbol = is_string($symbol) && trim($symbol) !== '' ? trim($symbol) : null;

        if ($symbol !== null && ($base !== null || $head !== null)) {
            throw new InvalidArgumentException('Choose either a symbol selection or a Git change set.');
        }

        if ($symbol !== null) {
            $changes = [$this->selectedSymbol($snapshot, $symbol)];
            $diagnostics = $snapshot->diagnostics;
        } else {
            [$changes, $diagnostics] = $this->gitChanges(
                $snapshot,
                $base ?? 'HEAD~1',
                $head ?? 'HEAD',
            );
        }

        if ($changes === []) {
            throw new InvalidArgumentException('The selected Git range contains no mappable symbol changes.');
        }

        $policy = new ImpactPolicy(new EdgeDirectionPolicy());

        return (new ImpactAnalyzer(
            $snapshot->graph,
            $diagnostics,
            $policy,
            new SharedResourceImpactAnalyzer($snapshot->graph, $policy),
            new TestScopeResolver($snapshot->graph),
            $relationOverlays,
        ))->analyze(new ImpactRequest(
            $changes,
            $this->maxNodes,
            $this->maxEdges,
            $this->maxDepth,
            $this->maxResponseBytes,
        ));
    }

    private function selectedSymbol(GraphSnapshot $snapshot, string $symbol): ChangedSymbol
    {
        $node = null;

        foreach ($snapshot->graph->nodes() as $candidate) {
            if ($candidate->id->value === $symbol || $candidate->qualifiedName === ltrim($symbol, '\\')) {
                if ($node !== null) {
                    throw new InvalidArgumentException('Symbol selection is ambiguous; use the canonical node ID.');
                }

                $node = $candidate;
            }
        }

        if ($node === null) {
            throw new InvalidArgumentException('Selected symbol does not exist in the active snapshot.');
        }

        $evidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'symbol-selection',
            Certainty::Certain,
            $node->location,
            $node->id->value,
            null,
            ['selection' => 'direct', 'snapshot_id' => $snapshot->id],
        );

        return new ChangedSymbol(
            ChangeType::Modified,
            $node->id,
            $node->id,
            $node->location?->file,
            $node->location?->file,
            $node->location?->startLine,
            $node->location?->endLine,
            $node->location?->startLine,
            $node->location?->endLine,
            $evidence,
            ['selection' => 'direct'],
        );
    }

    private function gitChanges(GraphSnapshot $snapshot, string $base, string $head): array
    {
        $runner = new NativeGitCommandRunner();
        $diff = (new GitDiffChangeProvider(
            $this->repositoryRoot,
            $runner,
            $this->gitTimeoutMs,
        ))->changes($base, $head);
        $symbols = $this->symbolTable($snapshot);
        $mapped = (new ChangedSymbolMapper($symbols))->map(
            $diff['files'],
            $diff['base_commit'],
            $diff['head_commit'],
        );
        $changes = array_values(array_filter(
            $mapped['symbols'],
            static fn (ChangedSymbol $change): bool => $change->changeType !== ChangeType::Renamed,
        ));
        $diagnostics = [
            ...$snapshot->diagnostics,
            ...$diff['diagnostics'],
            ...$mapped['diagnostics'],
        ];
        $baseResolver = new BaseRefSymbolResolver(
            new GitObjectReader($this->repositoryRoot, $runner, $this->gitTimeoutMs),
            $this->parser,
            $symbols,
            $diagnostics,
        );

        foreach ($diff['files'] as $file) {
            $oldSideFiles = [];

            if (in_array($file->changeType, [ChangeType::Deleted, ChangeType::Renamed], true)) {
                $oldSideFiles[] = $file;
            } else {
                foreach ($file->hunks as $hunk) {
                    if ($hunk->oldCount > 0 && $hunk->newCount === 0) {
                        $oldSideFiles[] = new ChangedFile(
                            ChangeType::Deleted,
                            $file->oldPath,
                            $file->newPath,
                            [$hunk],
                        );
                    }
                }
            }

            foreach ($oldSideFiles as $oldSideFile) {
                $resolved = $baseResolver->resolve(
                    $oldSideFile,
                    $diff['base_commit'],
                    $diff['head_commit'],
                );
                $changes = [...$changes, ...$resolved['symbols']];
                $diagnostics = [...$diagnostics, ...$resolved['diagnostics']];
            }
        }

        return [$changes, $diagnostics];
    }

    private function symbolTable(GraphSnapshot $snapshot): SymbolTable
    {
        $symbols = new SymbolTable();

        foreach ($snapshot->graph->nodes() as $node) {
            if ($node->location === null) {
                continue;
            }

            $prefix = substr($node->id->value, 0, (int) strpos($node->id->value, ':'));
            $kind = match ($prefix) {
                'class' => NodeKind::ClassSymbol,
                'interface' => NodeKind::InterfaceSymbol,
                'trait' => NodeKind::TraitSymbol,
                'enum' => NodeKind::EnumSymbol,
                'method' => NodeKind::Method,
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $symbols->add(new SymbolDefinition(
                $node->id,
                $kind,
                $node->name,
                $node->qualifiedName,
                $node->location,
                (array) ($node->attributes['declared_parameter_types'] ?? []),
                (array) ($node->attributes['declared_property_types'] ?? []),
                is_string($node->attributes['declared_return_type'] ?? null)
                    ? $node->attributes['declared_return_type']
                    : null,
                $node->attributes,
            ));
        }

        return $symbols;
    }
}
