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
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class CommandDetector
{
    /** @return list<Diagnostic> */
    public function detect(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        $candidates = $this->candidates($files, $symbols, $graph);
        $diagnostics = [];

        foreach ($bootFacts as $bootFact) {
            if (! $bootFact instanceof BootFact || $bootFact->kind !== 'command') {
                continue;
            }

            $name = $this->commandName($bootFact->attributes['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $matches = $candidates[$name] ?? [];

            if (count($matches) > 1) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::AmbiguousTarget,
                    'laravel_semantics',
                    null,
                    null,
                    null,
                    'Effective Artisan command name matches multiple in-scope command classes.',
                    [
                        'command' => $name,
                        'targets' => array_values(array_map(
                            static fn (array $match): string => $match['symbol']->id->value,
                            $matches,
                        )),
                    ],
                );

                continue;
            }

            if (count($matches) !== 1) {
                continue;
            }

            $match = $matches[0];
            $symbol = $match['symbol'];
            $property = $match['fact'];
            $commandId = NodeId::named(NodeKind::Command, $name);

            if (! $graph->hasNode($commandId)) {
                $graph->addNode(new GraphNode($commandId, NodeKind::Command, $name, null, null));
            }

            SemanticEdgeFactory::add(
                $graph,
                $commandId,
                EdgeType::ResolvesTo,
                $symbol->id,
                EvidenceOrigin::LaravelBoot,
                'command_detector',
                Certainty::Certain,
                new SourceLocation($property->file, $property->startLine, $property->endLine),
                $property->attributes['value'],
                'command:'.$name,
                'command:'.$name.':'.$symbol->id->value,
                ['effective' => true],
            );
        }

        return $diagnostics;
    }

    /** @return array<string, list<array{symbol: SymbolDefinition, fact: SemanticFact}>> */
    private function candidates(array $files, SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        $nodeKinds = [];

        foreach ($graph->nodes() as $node) {
            $nodeKinds[$node->id->value] = $node->kind;
        }

        $candidates = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('property_default') as $fact) {
                if (! in_array($fact->attributes['property'] ?? null, ['signature', 'name'], true)) {
                    continue;
                }

                $name = $this->commandName($this->literal($fact->attributes['value'] ?? null));

                if ($name === null) {
                    continue;
                }

                $owners = array_values(array_filter(
                    $symbols->smallestEnclosing($fact->file, $fact->startLine),
                    static fn (SymbolDefinition $symbol): bool => $symbol->structuralKind !== NodeKind::Method
                        && ($nodeKinds[$symbol->id->value] ?? null) === NodeKind::Command,
                ));

                if (count($owners) === 1) {
                    $candidates[$name][] = ['symbol' => $owners[0], 'fact' => $fact];
                }
            }
        }

        ksort($candidates, SORT_STRING);

        return $candidates;
    }

    private function literal(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (strlen($value) < 2 || ! in_array($value[0], ["'", '"'], true) || $value[-1] !== $value[0]) {
            return null;
        }

        return stripcslashes(substr($value, 1, -1));
    }

    private function commandName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($value));
        $name = $parts[0] ?? '';

        return $name === '' ? null : $name;
    }
}
