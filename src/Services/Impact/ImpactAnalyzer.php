<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Domain\Impact\ImpactReport;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class ImpactAnalyzer
{
    /** @var array<string, EvidenceRecord> */
    private array $queryEvidence = [];

    /** @var array<string, bool> */
    private array $nodeIds = [];

    private array $nodes = [];

    public function __construct(
        private readonly KnowledgeGraph $graph,
        private readonly array $diagnostics,
        private readonly ImpactPolicy $policy,
        private readonly SharedResourceImpactAnalyzer $sharedResources,
        private readonly TestScopeResolver $testScope,
        private readonly array $relationOverlays = [],
    ) {
        foreach ($graph->nodes() as $node) {
            $this->nodeIds[$node->id->value] = true;
            $this->nodes[$node->id->value] = $node;
        }

        foreach ($relationOverlays as $key => $edge) {
            if (! is_string($key) || ! $edge instanceof GraphEdge) {
                throw new \InvalidArgumentException('Impact runtime overlays must be relation-keyed graph edges.');
            }
        }
    }

    public function analyze(ImpactRequest $request): ImpactReport
    {
        $this->queryEvidence = [];

        foreach ($this->relationOverlays as $edge) {
            foreach ($edge->evidence as $record) {
                if ($record->origin === EvidenceOrigin::Runtime) {
                    $this->queryEvidence[$record->id()] = $record;
                }
            }
        }

        $reasons = [];
        $truncation = [];
        $changedIds = $this->changedIds($request->changedSymbols);

        foreach ([
            ImpactCategory::HardDependency,
            ImpactCategory::Workflow,
            ImpactCategory::Async,
            ImpactCategory::ExternalContract,
        ] as $category) {
            $result = $this->traverse($category, $request, $changedIds);

            if (in_array($category, [ImpactCategory::Workflow, ImpactCategory::ExternalContract], true)) {
                $expanded = $this->expandAffectedOneHop($category, $reasons, $request, $changedIds);
                $result['reasons'] = [...$result['reasons'], ...$expanded['reasons']];
                $result['stats'] = $this->mergeStats($result['stats'], $expanded['stats']);
            }

            $this->mergeReasons($reasons, $result['reasons']);
            $truncation[$category->value] = $result['stats'];
        }

        $shared = $this->sharedResources->analyze($request->changedSymbols, $request, $changedIds);
        $this->mergeReasons($reasons, $shared['reasons']);
        $truncation[ImpactCategory::SharedState->value] = $shared['stats'];

        $uncertainty = $this->uncertainty($request);
        $this->mergeReasons($reasons, $uncertainty['reasons']);
        $truncation[ImpactCategory::Uncertainty->value] = $uncertainty['stats'];

        $modules = $this->modules($request, $reasons, $changedIds);
        $this->mergeReasons($reasons, $modules['reasons']);
        $truncation[ImpactCategory::Module->value] = $modules['stats'];

        $testTargets = [];

        foreach (array_keys($reasons) as $nodeId) {
            $testTargets[$nodeId] = NodeId::fromString($nodeId);
        }

        foreach ($request->changedSymbols as $change) {
            $id = $change->newNodeId ?? $change->oldNodeId;

            if ($id !== null) {
                $testTargets[$id->value] = $id;
            }
        }

        $selectedTests = $this->testScope->resolve(array_values($testTargets), $request->maxNodes);
        $truncation[ImpactCategory::TestScope->value] = [
            'truncated' => false,
            'max_depth' => $selectedTests === [] ? 0 : 1,
            'visited_count' => count($selectedTests),
            'edge_count' => count($selectedTests),
            'omitted_count' => 0,
            'frontier' => [],
        ];

        $orderedTruncation = [];

        foreach (ImpactCategory::cases() as $category) {
            $orderedTruncation[$category->value] = $truncation[$category->value] ?? $this->emptyStats();
        }

        $affected = [];

        foreach ($reasons as $nodeId => $nodeReasons) {
            $affected[] = new AffectedSymbol(NodeId::fromString($nodeId), array_values($nodeReasons));
        }

        $evidence = [];
        $referencedEvidence = [];

        foreach ($reasons as $nodeReasons) {
            foreach ($nodeReasons as $reason) {
                foreach ($reason->evidenceIds as $id) {
                    $referencedEvidence[$id] = true;
                }
            }
        }

        foreach ($selectedTests as $test) {
            foreach ($test['evidence_ids'] as $id) {
                $referencedEvidence[$id] = true;
            }
        }

        foreach ($request->changedSymbols as $change) {
            $referencedEvidence[$change->evidence->id()] = true;
        }

        foreach ([...$this->graph->evidence(), ...array_map(
            static fn (ChangedSymbol $change): EvidenceRecord => $change->evidence,
            $request->changedSymbols,
        ), ...array_values($this->queryEvidence)] as $record) {
            if (isset($referencedEvidence[$record->id()])) {
                $evidence[$record->id()] = $record;
            }
        }

        return new ImpactReport(
            $request->changedSymbols,
            $affected,
            array_values($evidence),
            $orderedTruncation,
            $selectedTests,
        );
    }

    private function traverse(ImpactCategory $category, ImpactRequest $request, array $changedIds): array
    {
        $results = [];
        $visited = [];
        $seen = [];
        $edgeCount = 0;
        $omitted = 0;
        $frontier = [];
        $maxDepth = 0;

        foreach ($request->changedSymbols as $change) {
            if ($change->changeType === ChangeType::Added) {
                continue;
            }

            $seed = $change->changeType === ChangeType::Deleted
                ? $change->oldNodeId
                : ($change->newNodeId ?? $change->oldNodeId);

            if ($seed === null) {
                continue;
            }

            if (! isset($this->nodeIds[$seed->value])) {
                if ($category === ImpactCategory::HardDependency) {
                    $this->appendDiagnosticCallers(
                        $results,
                        $change,
                        $request,
                        $changedIds,
                        $visited,
                        $edgeCount,
                        $omitted,
                        $frontier,
                        $maxDepth,
                    );
                }

                continue;
            }

            $queue = [[
                'node' => $seed,
                'nodes' => [$seed->value],
                'edges' => [],
                'evidence' => [$change->evidence->id()],
                'depth' => 0,
            ]];
            $seen[$seed->value][$seed->value] = 0;

            while ($queue !== []) {
                $state = array_shift($queue);

                foreach ($this->adjacent($state['node'], $category) as [$edge, $next]) {
                    $depth = $state['depth'] + 1;

                    if ($depth > $request->maxDepth) {
                        $omitted++;
                        $frontier[$next->value] = true;

                        continue;
                    }

                    $previousDepth = $seen[$seed->value][$next->value] ?? null;

                    if ($previousDepth !== null && $previousDepth <= $depth) {
                        continue;
                    }

                    $seen[$seed->value][$next->value] = $depth;

                    if (isset($changedIds[$next->value]) || $this->isTestNode($next)) {
                        continue;
                    }

                    $newNode = ! isset($visited[$next->value]);

                    if ($edgeCount >= $request->maxEdges || ($newNode && count($visited) >= $request->maxNodes)) {
                        $omitted++;
                        $frontier[$next->value] = true;

                        continue;
                    }

                    $nodeChain = [...$state['nodes'], $next->value];
                    $edgeChain = [...$state['edges'], $edge->id];
                    $evidenceIds = $this->mergeEvidence($state['evidence'], $edge);
                    $level = $change->changeType === ChangeType::Deleted
                        ? ImpactLevel::Breaks
                        : ($depth === 1 ? ImpactLevel::Direct : ImpactLevel::Transitive);
                    $reason = new ImpactReason(
                        $category,
                        $level,
                        $nodeChain,
                        $edgeChain,
                        $evidenceIds,
                        "{$next->value} is {$level->value} through {$category->value} from {$seed->value}.",
                    );

                    $results[] = ['node_id' => $next, 'reason' => $reason];
                    $visited[$next->value] = true;
                    $edgeCount++;
                    $maxDepth = max($maxDepth, $depth);
                    $queue[] = [
                        'node' => $next,
                        'nodes' => $nodeChain,
                        'edges' => $edgeChain,
                        'evidence' => $evidenceIds,
                        'depth' => $depth,
                    ];
                }
            }

            if ($category === ImpactCategory::HardDependency) {
                $this->appendDiagnosticCallers(
                    $results,
                    $change,
                    $request,
                    $changedIds,
                    $visited,
                    $edgeCount,
                    $omitted,
                    $frontier,
                    $maxDepth,
                );
                $this->appendInterfaceImplementations(
                    $results,
                    $change,
                    $request,
                    $changedIds,
                    $visited,
                    $edgeCount,
                    $omitted,
                    $frontier,
                    $maxDepth,
                );
            }
        }

        $frontier = array_keys($frontier);
        sort($frontier, SORT_STRING);

        return [
            'reasons' => $results,
            'stats' => [
                'truncated' => $omitted > 0,
                'max_depth' => $maxDepth,
                'visited_count' => count($visited),
                'edge_count' => $edgeCount,
                'omitted_count' => $omitted,
                'frontier' => $frontier,
            ],
        ];
    }

    /** @return list<array{GraphEdge, NodeId}> */
    private function adjacent(NodeId $node, ImpactCategory $category): array
    {
        $adjacent = [];

        foreach ($this->policy->edgeTypes($category) as $type) {
            $direction = $this->policy->traversalDirection($category, $type);

            if (in_array($direction, ['forward', 'both'], true)) {
                foreach ($this->relationEdges($node, $type, true) as $edge) {
                    $adjacent[$edge->id.'|'.$edge->target->value] = [$edge, $edge->target];
                }
            }

            if (in_array($direction, ['reverse', 'both'], true)) {
                foreach ($this->relationEdges($node, $type, false) as $edge) {
                    $adjacent[$edge->id.'|'.$edge->source->value] = [$edge, $edge->source];
                }
            }
        }

        ksort($adjacent, SORT_STRING);

        return array_values($adjacent);
    }

    /** @return list<GraphEdge> */
    private function relationEdges(NodeId $node, EdgeType $type, bool $outgoing): array
    {
        $staticEdges = $outgoing
            ? $this->graph->outgoing($node, [$type])
            : $this->graph->incoming($node, [$type]);
        $edges = [];

        foreach ($staticEdges as $edge) {
            $key = $this->relationKey($edge);

            if (isset($this->relationOverlays[$key])) {
                $edges['relation:'.$key] = $this->relationOverlays[$key];
            } else {
                $edges['edge:'.$edge->id] = $edge;
            }
        }

        foreach ($this->relationOverlays as $key => $edge) {
            $matchesNode = $outgoing
                ? $edge->source->value === $node->value
                : $edge->target->value === $node->value;

            if ($matchesNode && $edge->type === $type) {
                $edges['relation:'.$key] = $edge;
            }
        }

        ksort($edges, SORT_STRING);

        return array_values($edges);
    }

    private function relationKey(GraphEdge $edge): string
    {
        return implode("\0", [$edge->source->value, $edge->target->value, $edge->type->value]);
    }

    private function expandAffectedOneHop(
        ImpactCategory $category,
        array $existingReasons,
        ImpactRequest $request,
        array $changedIds,
    ): array {
        $results = [];
        $visited = [];
        $edgeCount = 0;
        $omitted = 0;
        $frontier = [];

        foreach ($existingReasons as $nodeId => $reasons) {
            if (! isset($this->nodeIds[$nodeId])) {
                continue;
            }

            $source = NodeId::fromString($nodeId);

            foreach ($reasons as $baseReason) {
                $sources = [[
                    'node' => $source,
                    'nodes' => $baseReason->nodeChain,
                    'edges' => $baseReason->edgeChain,
                    'evidence' => $baseReason->evidenceIds,
                ]];

                if ($category === ImpactCategory::ExternalContract) {
                    $handler = $this->operationalHandler($source);

                    if ($handler !== null) {
                        $handlerEdge = 'operational-handler:'.hash('sha256', $source->value."\0".$handler->value);
                        $handlerNode = $this->nodes[$handler->value];
                        $handlerEvidence = new EvidenceRecord(
                            EvidenceOrigin::StaticAst,
                            'operational-handler-resolution',
                            Certainty::Certain,
                            $handlerNode->location,
                            $source->value.'::handle',
                            null,
                            ['source_id' => $source->value, 'handler_id' => $handler->value],
                        );
                        $this->queryEvidence[$handlerEvidence->id()] = $handlerEvidence;
                        $sources[] = [
                            'node' => $handler,
                            'nodes' => [...$baseReason->nodeChain, $handler->value],
                            'edges' => [...$baseReason->edgeChain, $handlerEdge],
                            'evidence' => [...$baseReason->evidenceIds, $handlerEvidence->id()],
                        ];
                    }
                }

                foreach ($sources as $state) {
                    foreach ($this->adjacent($state['node'], $category) as [$edge, $target]) {
                    if (isset($changedIds[$target->value]) || $this->isTestNode($target)) {
                        continue;
                    }

                    if ($edgeCount >= $request->maxEdges || (! isset($visited[$target->value]) && count($visited) >= $request->maxNodes)) {
                        $omitted++;
                        $frontier[$target->value] = true;

                        continue;
                    }

                    $results[] = [
                        'node_id' => $target,
                        'reason' => new ImpactReason(
                            $category,
                            ImpactLevel::Transitive,
                            [...$state['nodes'], $target->value],
                            [...$state['edges'], $edge->id],
                            $this->mergeEvidence($state['evidence'], $edge),
                            "{$target->value} is transitive through {$category->value} from affected symbol {$state['node']->value}.",
                        ),
                    ];
                    $visited[$target->value] = true;
                    $edgeCount++;
                    }
                }
            }
        }

        $frontier = array_keys($frontier);
        sort($frontier, SORT_STRING);

        return [
            'reasons' => $results,
            'stats' => [
                'truncated' => $omitted > 0,
                'max_depth' => $results === [] ? 0 : 1,
                'visited_count' => count($visited),
                'edge_count' => $edgeCount,
                'omitted_count' => $omitted,
                'frontier' => $frontier,
            ],
        ];
    }

    private function operationalHandler(NodeId $source): ?NodeId
    {
        $node = $this->nodes[$source->value] ?? null;

        if ($node === null
            || ! in_array($node->kind->value, ['command', 'job', 'listener'], true)
            || ! is_string($node->qualifiedName)) {
            return null;
        }

        $handler = NodeId::method($node->qualifiedName, 'handle');

        return isset($this->nodes[$handler->value]) ? $handler : null;
    }

    private function mergeStats(array $left, array $right): array
    {
        $frontier = array_values(array_unique([...$left['frontier'], ...$right['frontier']]));
        sort($frontier, SORT_STRING);

        return [
            'truncated' => $left['truncated'] || $right['truncated'],
            'max_depth' => max($left['max_depth'], $right['max_depth']),
            'visited_count' => $left['visited_count'] + $right['visited_count'],
            'edge_count' => $left['edge_count'] + $right['edge_count'],
            'omitted_count' => $left['omitted_count'] + $right['omitted_count'],
            'frontier' => $frontier,
        ];
    }

    private function appendDiagnosticCallers(
        array &$results,
        ChangedSymbol $change,
        ImpactRequest $request,
        array $changedIds,
        array &$visited,
        int &$edgeCount,
        int &$omitted,
        array &$frontier,
        int &$maxDepth,
    ): void {
        if (! in_array($change->changeType, [ChangeType::Deleted, ChangeType::Renamed], true) || $change->oldNodeId === null) {
            return;
        }

        $callers = array_values(array_filter(
            $change->attributes['diagnostic_callers'] ?? [],
            static fn ($caller): bool => is_string($caller) && $caller !== '',
        ));
        sort($callers, SORT_STRING);

        foreach ($callers as $callerValue) {
            if (isset($changedIds[$callerValue])) {
                continue;
            }

            if ($edgeCount >= $request->maxEdges || (! isset($visited[$callerValue]) && count($visited) >= $request->maxNodes)) {
                $omitted++;
                $frontier[$callerValue] = true;

                continue;
            }

            $caller = NodeId::fromString($callerValue);
            $diagnosticEdge = 'diagnostic:'.hash('sha256', $change->oldNodeId->value."\0".$callerValue);
            $sourceEvidenceIds = array_values(array_filter(
                $change->attributes['diagnostic_evidence_ids'] ?? [],
                static fn ($evidenceId): bool => is_string($evidenceId) && $evidenceId !== '',
            ));
            $linkEvidence = new EvidenceRecord(
                EvidenceOrigin::StaticAst,
                'unresolved-call-diagnostic',
                Certainty::Certain,
                null,
                null,
                null,
                [
                    'occurrence' => $diagnosticEdge,
                    'caller_id' => $callerValue,
                    'attempted_target_id' => $change->oldNodeId->value,
                    'source_evidence_ids' => $sourceEvidenceIds,
                ],
            );
            $this->queryEvidence[$linkEvidence->id()] = $linkEvidence;
            $evidence = [$change->evidence->id(), $linkEvidence->id()];

            $results[] = [
                'node_id' => $caller,
                'reason' => new ImpactReason(
                    ImpactCategory::HardDependency,
                    ImpactLevel::Breaks,
                    [$change->oldNodeId->value, $callerValue],
                    [$diagnosticEdge],
                    $evidence,
                    "{$callerValue} breaks because {$change->oldNodeId->value} was {$change->changeType->value}.",
                ),
            ];
            $visited[$callerValue] = true;
            $edgeCount++;
            $maxDepth = max($maxDepth, 1);
        }
    }

    private function appendInterfaceImplementations(
        array &$results,
        ChangedSymbol $change,
        ImpactRequest $request,
        array $changedIds,
        array &$visited,
        int &$edgeCount,
        int &$omitted,
        array &$frontier,
        int &$maxDepth,
    ): void {
        $seed = $change->newNodeId ?? $change->oldNodeId;

        if ($seed === null || preg_match('/^method:(.+)::([^:]+)$/', $seed->value, $matches) !== 1) {
            return;
        }

        $interfaceId = 'interface:'.$matches[1];

        if (! isset($this->nodeIds[$interfaceId])) {
            return;
        }

        $interface = NodeId::fromString($interfaceId);

        foreach ($this->graph->incoming($interface, [EdgeType::Implements]) as $edge) {
            $target = $edge->source;

            if (isset($changedIds[$target->value])) {
                continue;
            }

            if ($edgeCount >= $request->maxEdges || (! isset($visited[$target->value]) && count($visited) >= $request->maxNodes)) {
                $omitted++;
                $frontier[$target->value] = true;

                continue;
            }

            $level = $change->changeType === ChangeType::Deleted ? ImpactLevel::Breaks : ImpactLevel::Direct;
            $results[] = [
                'node_id' => $target,
                'reason' => new ImpactReason(
                    ImpactCategory::HardDependency,
                    $level,
                    [$seed->value, $target->value],
                    [$edge->id],
                    $this->mergeEvidence([$change->evidence->id()], $edge),
                    "{$target->value} implements the changed interface contract {$seed->value}.",
                ),
            ];
            $visited[$target->value] = true;
            $edgeCount++;
            $maxDepth = max($maxDepth, 1);
        }
    }

    private function uncertainty(ImpactRequest $request): array
    {
        $results = [];
        $visited = [];
        $omitted = 0;
        $frontier = [];

        foreach ($request->changedSymbols as $change) {
            $seed = $change->newNodeId ?? $change->oldNodeId;

            if ($seed === null) {
                continue;
            }

            foreach ($this->diagnostics as $diagnostic) {
                if (! $diagnostic instanceof Diagnostic || ! $this->diagnosticAdjacent($diagnostic, $change, $seed)) {
                    continue;
                }

                if (! isset($visited[$seed->value]) && count($visited) >= $request->maxNodes) {
                    $omitted++;
                    $frontier[$seed->value] = true;

                    continue;
                }

                $evidence = $this->diagnosticEvidence($diagnostic);
                $results[] = [
                    'node_id' => $seed,
                    'reason' => new ImpactReason(
                        ImpactCategory::Uncertainty,
                        ImpactLevel::Possible,
                        [$seed->value],
                        [],
                        [$change->evidence->id(), $evidence->id()],
                        "{$seed->value} has adjacent {$diagnostic->code->value} uncertainty.",
                    ),
                ];
                $visited[$seed->value] = true;
            }
        }

        $frontier = array_keys($frontier);
        sort($frontier, SORT_STRING);

        return [
            'reasons' => $results,
            'stats' => [
                'truncated' => $omitted > 0,
                'max_depth' => 0,
                'visited_count' => count($visited),
                'edge_count' => 0,
                'omitted_count' => $omitted,
                'frontier' => $frontier,
            ],
        ];
    }

    private function diagnosticAdjacent(Diagnostic $diagnostic, ChangedSymbol $change, NodeId $seed): bool
    {
        if (($diagnostic->attributes['enclosing_symbol_id'] ?? null) === $seed->value) {
            return true;
        }

        $path = $change->newPath ?? $change->oldPath;

        if ($path === null || $diagnostic->file !== $path) {
            return false;
        }

        $start = $change->newStartLine ?? $change->oldStartLine;
        $end = $change->newEndLine ?? $change->oldEndLine;

        return $start === null || $diagnostic->startLine === null
            || ($diagnostic->startLine <= $end && $diagnostic->endLine >= $start);
    }

    private function diagnosticEvidence(Diagnostic $diagnostic): EvidenceRecord
    {
        $location = $diagnostic->file !== null && $diagnostic->startLine !== null
            ? new SourceLocation($diagnostic->file, $diagnostic->startLine, $diagnostic->endLine)
            : null;
        $attributes = ['code' => $diagnostic->code->value, 'phase' => $diagnostic->phase] + $diagnostic->attributes;

        if ($location === null) {
            $attributes['occurrence'] = hash('sha256', serialize($diagnostic->toArray()));
        }

        $record = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'impact-diagnostic-adjacency',
            Certainty::Possible,
            $location,
            $diagnostic->message,
            null,
            $attributes,
        );
        $this->queryEvidence[$record->id()] = $record;

        return $record;
    }

    private function modules(ImpactRequest $request, array $reasons, array $changedIds): array
    {
        $sources = [];

        foreach ($request->changedSymbols as $change) {
            if ($change->changeType === ChangeType::Added) {
                continue;
            }

            $id = $change->newNodeId ?? $change->oldNodeId;

            if ($id !== null) {
                $sources[] = [
                    'node' => $id,
                    'nodes' => [$id->value],
                    'edges' => [],
                    'evidence' => [$change->evidence->id()],
                ];
            }
        }

        foreach ($reasons as $nodeId => $nodeReasons) {
            foreach ($nodeReasons as $reason) {
                $sources[] = [
                    'node' => NodeId::fromString($nodeId),
                    'nodes' => $reason->nodeChain,
                    'edges' => $reason->edgeChain,
                    'evidence' => $reason->evidenceIds,
                ];
            }
        }

        $results = [];
        $visited = [];
        $edgeCount = 0;
        $omitted = 0;
        $frontier = [];

        foreach ($sources as $source) {
            if (! isset($this->nodeIds[$source['node']->value])) {
                continue;
            }

            foreach ($this->graph->outgoing($source['node'], [EdgeType::MemberOfModule]) as $edge) {
                $module = $edge->target;

                if (isset($changedIds[$module->value])) {
                    continue;
                }

                if ($edgeCount >= $request->maxEdges || (! isset($visited[$module->value]) && count($visited) >= $request->maxNodes)) {
                    $omitted++;
                    $frontier[$module->value] = true;

                    continue;
                }

                $results[] = [
                    'node_id' => $module,
                    'reason' => new ImpactReason(
                        ImpactCategory::Module,
                        ImpactLevel::Direct,
                        [...$source['nodes'], $module->value],
                        [...$source['edges'], $edge->id],
                        $this->mergeEvidence($source['evidence'], $edge),
                        "{$module->value} contains affected symbol {$source['node']->value}.",
                    ),
                ];
                $visited[$module->value] = true;
                $edgeCount++;
            }
        }

        $frontier = array_keys($frontier);
        sort($frontier, SORT_STRING);

        return [
            'reasons' => $results,
            'stats' => [
                'truncated' => $omitted > 0,
                'max_depth' => $results === [] ? 0 : 1,
                'visited_count' => count($visited),
                'edge_count' => $edgeCount,
                'omitted_count' => $omitted,
                'frontier' => $frontier,
            ],
        ];
    }

    private function changedIds(array $changes): array
    {
        $ids = [];

        foreach ($changes as $change) {
            foreach ([$change->oldNodeId, $change->newNodeId] as $id) {
                if ($id !== null) {
                    $ids[$id->value] = true;
                }
            }
        }

        return $ids;
    }

    private function mergeReasons(array &$target, array $reasons): void
    {
        foreach ($reasons as $item) {
            $reason = $item['reason'];
            $target[$item['node_id']->value][$reason->key()] = $reason;
        }
    }

    private function mergeEvidence(array $ids, GraphEdge $edge): array
    {
        foreach ($edge->evidence as $evidence) {
            $ids[] = $evidence->id();
        }

        return array_values(array_unique($ids));
    }

    private function emptyStats(): array
    {
        return [
            'truncated' => false,
            'max_depth' => 0,
            'visited_count' => 0,
            'edge_count' => 0,
            'omitted_count' => 0,
            'frontier' => [],
        ];
    }

    private function isTestNode(NodeId $id): bool
    {
        $node = $this->nodes[$id->value] ?? null;

        return $node !== null && ($node->kind->value === 'test'
            || str_starts_with($node->location?->file ?? '', 'tests/'));
    }
}
