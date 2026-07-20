<?php

namespace DNDark\LogicMap\Repositories\Database;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use RuntimeException;

trait HydratesGraphRows
{
    private function hydrateNode(object $row): GraphNode
    {
        $location = $row->file === null
            ? null
            : new SourceLocation($row->file, (int) $row->start_line, (int) $row->end_line);

        return new GraphNode(
            NodeId::fromString($row->node_id),
            NodeKind::from($row->kind),
            $row->name,
            $row->qualified_name,
            $location,
            $this->decodeJson($row->attributes),
        );
    }

    /** @param list<EvidenceRecord> $evidence */
    private function hydrateEdge(object $row, array $evidence): GraphEdge
    {
        return new GraphEdge(
            $row->edge_id,
            NodeId::fromString($row->source_id),
            NodeId::fromString($row->target_id),
            EdgeType::from($row->type),
            $row->site_key,
            $evidence,
        );
    }

    private function hydrateEvidence(object $row): EvidenceRecord
    {
        $location = $row->file === null
            ? null
            : new SourceLocation($row->file, (int) $row->start_line, (int) $row->end_line);
        $record = new EvidenceRecord(
            EvidenceOrigin::from($row->origin),
            $row->detector,
            Certainty::from($row->certainty),
            $location,
            $row->expression,
            $row->condition_text,
            $this->decodeJson($row->attributes),
        );

        if (! hash_equals($row->evidence_id, $record->id())) {
            throw new RuntimeException('Stored evidence identity is corrupt.');
        }

        return $record;
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Stored graph JSON must decode to an array.');
        }

        return $decoded;
    }
}
