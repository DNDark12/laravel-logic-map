<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Support\NodeIdCodec;

/**
 * Full-snapshot machine-readable graph export for the AI documentation bundle.
 * This is an index/export-flow consumer, so it deliberately uses the full
 * nodes()/edges() materializers rather than the targeted GraphReader methods
 * query services use — the whole point of this projector is to emit the
 * entire graph once, offline. Output is sorted by canonical ID throughout so
 * two exports of the same snapshot are byte-identical.
 */
final class GraphJsonProjector
{
    private const ENTRYPOINT_KINDS = ['route', 'command', 'schedule', 'job', 'event'];

    public function __construct(private readonly NodeIdCodec $codec = new NodeIdCodec())
    {
    }

    public function project(GraphSnapshot $snapshot): array
    {
        $nodes = $snapshot->graph->nodes();
        $edges = $snapshot->graph->edges();

        $nodeModule = [];

        foreach ($edges as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $nodeModule[$edge->source->value] = $edge->target->value;
            }
        }

        $nodeRows = array_map(
            fn (GraphNode $node): array => $this->node($node, $nodeModule[$node->id->value] ?? null),
            $nodes,
        );
        $edgeRows = array_map(fn (GraphEdge $edge): array => $this->edge($edge), $edges);
        $modules = $this->modules($nodes, $nodeModule);

        return [
            'schema_version' => $snapshot->schemaVersion,
            'analysis_version' => $snapshot->analysisVersion,
            'snapshot_id' => $snapshot->id,
            'fingerprint' => $snapshot->sourceFingerprint,
            'nodes' => $nodeRows,
            'edges' => $edgeRows,
            'modules' => $modules,
        ];
    }

    public function json(GraphSnapshot $snapshot): string
    {
        return json_encode(
            $this->project($snapshot),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    private function node(GraphNode $node, ?string $moduleId): array
    {
        return [
            'id' => $node->id->value,
            'encoded_id' => $this->codec->encode($node->id->value),
            'kind' => $node->kind->value,
            'name' => $node->name,
            'qualified_name' => $node->qualifiedName,
            'module' => $moduleId,
            'file' => $node->location?->file,
            'start_line' => $node->location?->startLine,
            'end_line' => $node->location?->endLine,
            'classification_certainty' => $node->attributes['classification_certainty'] ?? null,
        ];
    }

    private function edge(GraphEdge $edge): array
    {
        return [
            'id' => $edge->id,
            'source' => $edge->source->value,
            'target' => $edge->target->value,
            'type' => $edge->type->value,
            'confidence' => $this->strongestCertainty($edge->evidence)->value,
            'evidence_ids' => array_map(
                static fn (EvidenceRecord $record): string => $record->id(),
                $edge->evidence,
            ),
        ];
    }

    /** @param list<GraphNode> $nodes
     *  @param array<string,string> $nodeModule
     *  @return list<array{id:string,encoded_id:string,name:string,member_count:int,entrypoint_ids:list<string>}>
     */
    private function modules(array $nodes, array $nodeModule): array
    {
        $byId = [];

        foreach ($nodes as $node) {
            $byId[$node->id->value] = $node;
        }

        $members = [];

        foreach ($nodeModule as $memberId => $moduleId) {
            $members[$moduleId][] = $memberId;
        }

        $modules = [];

        foreach ($nodes as $node) {
            if ($node->kind !== NodeKind::Module) {
                continue;
            }

            $memberIds = $members[$node->id->value] ?? [];
            sort($memberIds, SORT_STRING);
            $entrypointIds = array_values(array_filter(
                $memberIds,
                static function (string $memberId) use ($byId): bool {
                    return isset($byId[$memberId])
                        && in_array($byId[$memberId]->kind->value, self::ENTRYPOINT_KINDS, true);
                },
            ));

            $modules[] = [
                'id' => $node->id->value,
                'encoded_id' => $this->codec->encode($node->id->value),
                'name' => $node->name,
                'member_count' => count($memberIds),
                'entrypoint_ids' => $entrypointIds,
            ];
        }

        usort($modules, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $modules;
    }

    private function strongestCertainty(array $evidence): Certainty
    {
        $rank = ['possible' => 0, 'probable' => 1, 'certain' => 2];
        $strongest = Certainty::Possible;

        foreach ($evidence as $record) {
            if ($rank[$record->certainty->value] > $rank[$strongest->value]) {
                $strongest = $record->certainty;
            }
        }

        return $strongest;
    }
}
