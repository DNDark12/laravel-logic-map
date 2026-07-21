<?php

namespace DNDark\LogicMap\Repositories\Database;

use DateTimeImmutable;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Domain\Snapshot\ProcessStepRecord;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Support\CanonicalJson;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Stores snapshots through the application's configured database connection,
 * so any Laravel-supported driver (MySQL, Postgres, SQLite, ...) works.
 * Snapshots returned from find()/active() carry a lazy DatabaseGraph; the
 * graph itself is never fully hydrated unless a caller explicitly asks for
 * full materialization.
 */
final class DatabaseGraphRepository implements SemanticGraphRepository
{
    use HydratesGraphRows;

    private const CHUNK = 500;

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function store(GraphSnapshot $snapshot): void
    {
        $this->connection->transaction(function () use ($snapshot): void {
            $this->insertSnapshot($snapshot);
            $this->insertFiles($snapshot);
            $this->insertNodes($snapshot);
            $this->insertEvidence($snapshot);
            $this->insertEdges($snapshot);
            $this->insertDiagnostics($snapshot);
            $this->insertProcessSteps($snapshot);
        });
    }

    public function activate(string $snapshotId): void
    {
        $exists = $this->connection->table('lm_snapshots')->where('id', $snapshotId)->exists();

        if (! $exists) {
            throw new RuntimeException("Cannot activate missing snapshot {$snapshotId}.");
        }

        $this->connection->table('lm_active_snapshot')->updateOrInsert(
            ['singleton' => 1],
            ['snapshot_id' => $snapshotId],
        );
    }

    public function active(): ?GraphSnapshot
    {
        $id = $this->activeId();

        return $id === null ? null : $this->find($id);
    }

    public function activeId(): ?string
    {
        $id = $this->connection->table('lm_active_snapshot')->where('singleton', 1)->value('snapshot_id');

        return is_string($id) ? $id : null;
    }

    public function find(string $snapshotId): ?GraphSnapshot
    {
        $row = $this->connection->table('lm_snapshots')->where('id', $snapshotId)->first();

        if ($row === null) {
            return null;
        }

        return new GraphSnapshot(
            $row->id,
            (int) $row->schema_version,
            $row->analysis_version,
            new DateTimeImmutable($row->indexed_at),
            $row->source_fingerprint,
            $this->loadFiles($snapshotId),
            new DatabaseGraph($this->connection, $snapshotId),
            $this->loadDiagnostics($snapshotId),
            $this->decodeJson($row->phase_metrics),
            $this->loadProcessSteps($snapshotId),
        );
    }

    public function list(): array
    {
        $ids = $this->connection->table('lm_snapshots')
            ->orderByDesc('indexed_at')
            ->orderBy('id')
            ->pluck('id');
        $snapshots = [];

        foreach ($ids as $id) {
            $snapshot = $this->find($id);

            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    public function clear(): void
    {
        $this->connection->transaction(function (): void {
            foreach ([
                'lm_runtime_observations',
                'lm_runtime_sessions',
                'lm_process_steps',
                'lm_diagnostics',
                'lm_edge_evidence',
                'lm_evidence',
                'lm_edges',
                'lm_nodes',
                'lm_files',
                'lm_active_snapshot',
                'lm_snapshots',
            ] as $table) {
                $this->connection->table($table)->delete();
            }
        });
    }

    private function insertSnapshot(GraphSnapshot $snapshot): void
    {
        $this->connection->table('lm_snapshots')->insert([
            'id' => $snapshot->id,
            'schema_version' => $snapshot->schemaVersion,
            'analysis_version' => $snapshot->analysisVersion,
            'indexed_at' => $snapshot->indexedAt->format(DATE_ATOM),
            'source_fingerprint' => $snapshot->sourceFingerprint,
            'phase_metrics' => CanonicalJson::encode($snapshot->phaseMetrics),
        ]);
    }

    private function insertFiles(GraphSnapshot $snapshot): void
    {
        $this->insertChunked('lm_files', array_map(static fn (IndexedFile $file): array => [
            'snapshot_id' => $snapshot->id,
            'path' => $file->path,
            'content_hash' => $file->contentHash,
            'size' => $file->size,
        ], $snapshot->files));
    }

    private function insertNodes(GraphSnapshot $snapshot): void
    {
        $rows = [];

        foreach ($snapshot->graph->nodes() as $node) {
            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'node_id' => $node->id->value,
                'kind' => $node->kind->value,
                'name' => $node->name,
                'qualified_name' => $node->qualifiedName,
                'file' => $node->location?->file,
                'start_line' => $node->location?->startLine,
                'end_line' => $node->location?->endLine,
                'attributes' => CanonicalJson::encode($node->attributes),
            ];
        }

        $this->insertChunked('lm_nodes', $rows);
    }

    private function insertEvidence(GraphSnapshot $snapshot): void
    {
        $rows = [];

        foreach ($snapshot->graph->evidence() as $evidence) {
            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'evidence_id' => $evidence->id(),
                'origin' => $evidence->origin->value,
                'detector' => $evidence->detector,
                'certainty' => $evidence->certainty->value,
                'file' => $evidence->location?->file,
                'start_line' => $evidence->location?->startLine,
                'end_line' => $evidence->location?->endLine,
                'expression' => $evidence->expression,
                'condition_text' => $evidence->condition,
                'attributes' => CanonicalJson::encode($evidence->attributes),
            ];
        }

        $this->insertChunked('lm_evidence', $rows);
    }

    private function insertEdges(GraphSnapshot $snapshot): void
    {
        $edgeRows = [];
        $linkRows = [];

        foreach ($snapshot->graph->edges() as $edge) {
            $edgeRows[] = [
                'snapshot_id' => $snapshot->id,
                'edge_id' => $edge->id,
                'source_id' => $edge->source->value,
                'target_id' => $edge->target->value,
                'type' => $edge->type->value,
                'site_key' => $edge->siteKey,
            ];

            foreach ($edge->evidence as $evidence) {
                $linkRows[] = [
                    'snapshot_id' => $snapshot->id,
                    'edge_id' => $edge->id,
                    'evidence_id' => $evidence->id(),
                ];
            }
        }

        $this->insertChunked('lm_edges', $edgeRows);
        $this->insertChunked('lm_edge_evidence', $linkRows);
    }

    private function insertDiagnostics(GraphSnapshot $snapshot): void
    {
        $this->insertChunked('lm_diagnostics', array_map(static fn (Diagnostic $diagnostic): array => [
            'snapshot_id' => $snapshot->id,
            'code' => $diagnostic->code->value,
            'phase' => $diagnostic->phase,
            'file' => $diagnostic->file,
            'start_line' => $diagnostic->startLine,
            'end_line' => $diagnostic->endLine,
            'message' => $diagnostic->message,
            'attributes' => CanonicalJson::encode($diagnostic->attributes),
        ], $snapshot->diagnostics));
    }

    private function insertProcessSteps(GraphSnapshot $snapshot): void
    {
        $knownEvidence = array_fill_keys(array_map(
            static fn (EvidenceRecord $record): string => $record->id(),
            $snapshot->graph->evidence(),
        ), true);
        $rows = [];

        foreach ($snapshot->processSteps as $step) {
            foreach ($step->evidenceIds as $evidenceId) {
                if (! isset($knownEvidence[$evidenceId])) {
                    throw new RuntimeException(
                        "Snapshot {$snapshot->id} references missing process-step evidence {$evidenceId}.",
                    );
                }
            }

            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'process_id' => $step->processId->value,
                'ordinal' => $step->ordinal,
                'step_id' => $step->stepId,
                'node_id' => $step->nodeId?->value,
                'step_kind' => $step->stepKind->value,
                'boundary' => $step->boundary->value,
                'evidence_ids' => CanonicalJson::encode($step->evidenceIds),
                'attributes' => CanonicalJson::encode($step->attributes),
            ];
        }

        $this->insertChunked('lm_process_steps', $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->connection->table($table)->insert($chunk);
        }
    }

    /** @return list<IndexedFile> */
    private function loadFiles(string $snapshotId): array
    {
        return $this->connection->table('lm_files')
            ->where('snapshot_id', $snapshotId)
            ->orderBy('path')
            ->get(['path', 'content_hash', 'size'])
            ->map(static fn (object $row): IndexedFile => new IndexedFile(
                $row->path,
                $row->content_hash,
                (int) $row->size,
            ))
            ->all();
    }

    /** @return list<Diagnostic> */
    private function loadDiagnostics(string $snapshotId): array
    {
        return $this->connection->table('lm_diagnostics')
            ->where('snapshot_id', $snapshotId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): Diagnostic => new Diagnostic(
                DiagnosticCode::from($row->code),
                $row->phase,
                $row->file,
                $row->start_line === null ? null : (int) $row->start_line,
                $row->end_line === null ? null : (int) $row->end_line,
                $row->message,
                $this->decodeJson($row->attributes),
            ))
            ->all();
    }

    /** @return list<ProcessStepRecord> */
    private function loadProcessSteps(string $snapshotId): array
    {
        return $this->connection->table('lm_process_steps')
            ->where('snapshot_id', $snapshotId)
            ->orderBy('process_id')
            ->orderBy('ordinal')
            ->orderBy('step_id')
            ->get()
            ->map(fn (object $row): ProcessStepRecord => new ProcessStepRecord(
                NodeId::fromString($row->process_id),
                (int) $row->ordinal,
                $row->step_id,
                $row->node_id === null ? null : NodeId::fromString($row->node_id),
                WorkflowStepKind::from($row->step_kind),
                ExecutionBoundary::from($row->boundary),
                $this->decodeJson($row->evidence_ids),
                $this->decodeJson($row->attributes),
            ))
            ->all();
    }
}
