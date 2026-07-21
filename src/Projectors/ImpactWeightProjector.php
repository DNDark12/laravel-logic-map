<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ImpactReport;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Services\Impact\ImpactWeightModel;
use DNDark\LogicMap\Support\NodeIdCodec;

/**
 * Wraps an already-computed ImpactReport (produced by
 * ImpactQueryService::analyze() in symbol mode) with an explainable weight
 * per affected symbol. Impact traversal is never re-derived here — this
 * projector only maps ImpactReason -> ImpactWeight and shapes the result for
 * the AI documentation bundle. Rows are sorted by descending score, then by
 * canonical node ID, so two exports of the same report are byte-identical.
 */
final class ImpactWeightProjector
{
    public function __construct(
        private readonly ImpactWeightModel $model = new ImpactWeightModel(),
        private readonly NodeIdCodec $codec = new NodeIdCodec(),
    ) {
    }

    public function project(GraphSnapshot $snapshot, ImpactReport $report, string $target): array
    {
        $evidenceById = [];

        foreach ($report->evidence as $record) {
            $evidenceById[$record->id()] = $record;
        }

        $rows = array_map(
            fn (AffectedSymbol $symbol): array => $this->row($snapshot, $symbol, $evidenceById),
            $report->affectedSymbols,
        );

        usort($rows, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['node_id'] <=> $right['node_id'];
        });

        return [
            'target' => $target,
            'snapshot_id' => $snapshot->id,
            'truncation' => $report->truncation,
            'affected' => $rows,
        ];
    }

    public function json(GraphSnapshot $snapshot, ImpactReport $report, string $target): string
    {
        return json_encode(
            $this->project($snapshot, $report, $target),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /** @param array<string,\DNDark\LogicMap\Domain\Graph\EvidenceRecord> $evidenceById */
    private function row(GraphSnapshot $snapshot, AffectedSymbol $symbol, array $evidenceById): array
    {
        $coveringEdges = $snapshot->graph->incoming($symbol->nodeId, [EdgeType::CoveredByTest]);
        $testCovered = $coveringEdges !== [];

        $best = $this->model->bestReason($symbol->reasons, $evidenceById);
        $weight = $this->model->aggregate($symbol->reasons, $evidenceById, $testCovered);

        $suggestedTests = array_values(array_unique(array_map(
            static fn (GraphEdge $edge): string => $edge->source->value,
            $coveringEdges,
        )));
        sort($suggestedTests, SORT_STRING);

        return [
            'node_id' => $symbol->nodeId->value,
            'encoded_id' => $this->codec->encode($symbol->nodeId->value),
            'category' => $best['reason']->category->value,
            'level' => $best['reason']->level->value,
            'score' => round($weight->score, 4),
            'band' => $weight->band->value,
            'factors' => $weight->factors,
            'reason_chain' => $best['reason']->nodeChain,
            'evidence_ids' => $best['reason']->evidenceIds,
            'suggested_tests' => $suggestedTests,
        ];
    }
}
