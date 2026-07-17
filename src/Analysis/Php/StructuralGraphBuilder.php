<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;

final readonly class StructuralGraphBuilder
{
    public function __construct(private SymbolTable $symbols)
    {
    }

    /**
     * @param list<ParsedFile> $files
     * @return list<Diagnostic>
     */
    public function build(array $files, KnowledgeGraph $graph): array
    {
        $diagnostics = [];
        $symbolsById = [];

        foreach ($files as $file) {
            $diagnostics = [...$diagnostics, ...$file->diagnostics];
            $fileId = NodeId::file($file->relativePath);
            $graph->addNode(new GraphNode(
                $fileId,
                NodeKind::File,
                basename($file->relativePath),
                null,
                null,
                ['path' => $file->relativePath, 'content_hash' => $file->contentHash],
            ));

            foreach ($file->symbols as $symbol) {
                $symbolsById[$symbol->id->value][] = $symbol;
            }
        }

        foreach ($symbolsById as $id => $definitions) {
            if (count($definitions) !== 1) {
                continue;
            }

            $symbol = $definitions[0];
            $graph->addNode(new GraphNode(
                $symbol->id,
                $symbol->structuralKind,
                $symbol->name,
                $symbol->qualifiedName,
                $symbol->location,
                $symbol->attributes + [
                    'declared_parameter_types' => $symbol->declaredParameterTypes,
                    'declared_property_types' => $symbol->declaredPropertyTypes,
                    'declared_return_type' => $symbol->declaredReturnType,
                ],
            ));
        }

        foreach ($files as $file) {
            $fileId = NodeId::file($file->relativePath);

            foreach ($file->symbols as $symbol) {
                if (count($symbolsById[$symbol->id->value] ?? []) !== 1) {
                    continue;
                }

                $graph->addEdge(GraphEdge::fromEvidence(
                    $fileId,
                    $symbol->id,
                    EdgeType::Contains,
                    $this->evidence(
                        'structural-containment',
                        $symbol->location,
                        $symbol->id->value,
                    ),
                ));

                if ($symbol->structuralKind === NodeKind::Method) {
                    $owner = $symbol->attributes['owner_id'] ?? null;

                    if (is_string($owner)) {
                        $ownerId = NodeId::fromString($owner);

                        if ($graph->hasNode($ownerId)) {
                            $graph->addEdge(GraphEdge::fromEvidence(
                                $ownerId,
                                $symbol->id,
                                EdgeType::Defines,
                                $this->evidence(
                                    'structural-definition',
                                    $symbol->location,
                                    $symbol->id->value,
                                ),
                            ));
                        }
                    }
                }
            }

            foreach ($file->inheritanceFacts as $fact) {
                if (! $graph->hasNode($fact->sourceSymbolId)) {
                    continue;
                }

                $targets = $this->symbols->exact($fact->targetQualifiedName);

                if (count($targets) > 1) {
                    continue;
                }

                if (count($targets) === 1) {
                    $targetId = $targets[0]->id;
                } else {
                    $kind = match ($fact->relation) {
                        'implements' => NodeKind::InterfaceSymbol,
                        'uses_trait' => NodeKind::TraitSymbol,
                        default => NodeKind::ClassSymbol,
                    };
                    $targetId = NodeId::symbol($kind, $fact->targetQualifiedName);
                    $graph->addNode(new GraphNode(
                        $targetId,
                        $kind,
                        substr($fact->targetQualifiedName, strrpos($fact->targetQualifiedName, '\\') + 1),
                        $fact->targetQualifiedName,
                        null,
                        ['external_declaration' => true],
                    ));
                }

                if (! $graph->hasNode($targetId)) {
                    continue;
                }

                $edgeType = match ($fact->relation) {
                    'extends' => EdgeType::Extends,
                    'implements' => EdgeType::Implements,
                    'uses_trait' => EdgeType::UsesTrait,
                };
                $graph->addEdge(GraphEdge::fromEvidence(
                    $fact->sourceSymbolId,
                    $targetId,
                    $edgeType,
                    $this->evidence(
                        'structural-inheritance',
                        new SourceLocation($fact->file, $fact->startLine, $fact->endLine),
                        $fact->targetQualifiedName,
                    ),
                ));
            }
        }

        return $diagnostics;
    }

    private function evidence(
        string $detector,
        SourceLocation $location,
        string $expression,
    ): EvidenceRecord {
        return new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            $detector,
            Certainty::Certain,
            $location,
            $expression,
        );
    }
}
