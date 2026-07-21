<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphReader;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;

final readonly class SharedResourceImpactAnalyzer
{
    public function __construct(
        private GraphReader $graph,
        private ImpactPolicy $policy,
    ) {
    }

    public function analyze(array $changes, ImpactRequest $request, array $changedIds): array
    {
        $results = [];
        $visited = [];
        $edgeCount = 0;
        $omitted = 0;
        $frontier = [];
        $maxDepth = 0;
        $changesBySeed = [];

        foreach ($changes as $change) {
            if (! $change instanceof ChangedSymbol || $change->changeType === ChangeType::Added) {
                continue;
            }

            $seed = $change->changeType === ChangeType::Deleted
                ? $change->oldNodeId
                : ($change->newNodeId ?? $change->oldNodeId);

            if ($seed === null || $request->maxDepth < 2) {
                continue;
            }

            $changesBySeed[$seed->value][] = $change;
        }

        $knownSeeds = $this->graph->nodesByIds(array_keys($changesBySeed));
        $resourceEdgesBySeed = [];
        $resourceIds = [];
        $oppositeTypes = [];

        foreach ($this->graph->edgesTouching(
            array_keys($knownSeeds),
            $this->policy->edgeTypes(ImpactCategory::SharedState),
        ) as $edge) {
            if (! isset($knownSeeds[$edge->source->value])) {
                continue;
            }

            $matching = $this->matchingOppositeTypes($edge->type);

            if ($matching === []) {
                continue;
            }

            $resourceEdgesBySeed[$edge->source->value][] = $edge;
            $resourceIds[$edge->target->value] = true;

            foreach ($matching as $type) {
                $oppositeTypes[$type->value] = $type;
            }
        }

        $consumerEdgesByResource = [];

        foreach ($this->graph->edgesTouching(array_keys($resourceIds), array_values($oppositeTypes)) as $edge) {
            if (isset($resourceIds[$edge->target->value])) {
                $consumerEdgesByResource[$edge->target->value][] = $edge;
            }
        }

        foreach ($changesBySeed as $seedValue => $seedChanges) {
            if (! isset($knownSeeds[$seedValue])) {
                continue;
            }

            $seed = $knownSeeds[$seedValue]->id;

            foreach ($seedChanges as $change) {
                foreach ($resourceEdgesBySeed[$seedValue] ?? [] as $resourceEdge) {
                    $matching = $this->matchingOppositeTypes($resourceEdge->type);
                    $resourceId = $resourceEdge->target;
                    $visited[$resourceId->value] = true;

                    foreach ($consumerEdgesByResource[$resourceId->value] ?? [] as $consumerEdge) {
                        if (! in_array($consumerEdge->type, $matching, true)) {
                            continue;
                        }

                    $target = $consumerEdge->source;

                    if ($target->equals($seed) || isset($changedIds[$target->value])) {
                        continue;
                    }

                    if ($edgeCount + 2 > $request->maxEdges || (! isset($visited[$target->value]) && count($visited) >= $request->maxNodes)) {
                        $omitted++;
                        $frontier[$target->value] = true;

                        continue;
                    }

                    $visited[$target->value] = true;
                    $edgeCount += 2;
                    $maxDepth = 2;
                    $results[] = [
                        'node_id' => $target,
                        'reason' => new ImpactReason(
                            ImpactCategory::SharedState,
                            ImpactLevel::SharedResource,
                            [$seed->value, $resourceId->value, $target->value],
                            [$resourceEdge->id, $consumerEdge->id],
                            $this->evidenceIds($change, [$resourceEdge, $consumerEdge]),
                            "{$target->value} shares {$resourceId->value} with changed symbol {$seed->value}.",
                        ),
                    ];
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
                'max_depth' => $maxDepth,
                'visited_count' => count($visited),
                'edge_count' => $edgeCount,
                'omitted_count' => $omitted,
                'frontier' => $frontier,
            ],
        ];
    }

    /** @return list<EdgeType> */
    private function matchingOppositeTypes(EdgeType $type): array
    {
        return match ($type) {
            EdgeType::WritesModel => [EdgeType::ReadsModel],
            EdgeType::WritesTable => [EdgeType::ReadsTable],
            EdgeType::WritesColumn => [EdgeType::ReadsColumn],
            EdgeType::WritesCache, EdgeType::InvalidatesCache => [EdgeType::ReadsCache],
            EdgeType::WritesStorage => [EdgeType::ReadsStorage],
            EdgeType::ReadsModel => [EdgeType::WritesModel],
            EdgeType::ReadsTable => [EdgeType::WritesTable],
            EdgeType::ReadsColumn => [EdgeType::WritesColumn],
            EdgeType::ReadsCache => [EdgeType::WritesCache, EdgeType::InvalidatesCache],
            EdgeType::ReadsStorage => [EdgeType::WritesStorage],
            default => [],
        };
    }

    /** @param list<GraphEdge> $edges */
    private function evidenceIds(ChangedSymbol $change, array $edges): array
    {
        $ids = [$change->evidence->id()];

        foreach ($edges as $edge) {
            foreach ($edge->evidence as $evidence) {
                $ids[] = $evidence->id();
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids;
    }
}
