<?php

namespace DNDark\LogicMap\Repositories\Sqlite;

use DateTimeImmutable;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
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
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Domain\Snapshot\ProcessStepRecord;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Support\CanonicalJson;
use PDO;
use RuntimeException;
use Throwable;

final class SqliteGraphRepository implements SemanticGraphRepository
{
    private readonly PDO $connection;

    public function __construct(
        SqliteConnectionFactory $factory,
        ?SqliteSchema $schema = null,
    ) {
        $this->connection = $factory->connection();
        ($schema ?? new SqliteSchema())->ensure($this->connection);
    }

    public function store(GraphSnapshot $snapshot): void
    {
        $this->connection->beginTransaction();

        try {
            $this->insertSnapshot($snapshot);
            $this->insertFiles($snapshot);
            $this->insertNodes($snapshot);
            $this->insertEvidence($snapshot);
            $this->insertEdges($snapshot);
            $this->insertDiagnostics($snapshot);
            $this->insertProcessSteps($snapshot);
            $this->connection->commit();
        } catch (Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $throwable;
        }
    }

    public function activate(string $snapshotId): void
    {
        $exists = $this->connection->prepare('SELECT 1 FROM lm_snapshots WHERE id = ?');
        $exists->execute([$snapshotId]);

        if ($exists->fetchColumn() === false) {
            throw new RuntimeException("Cannot activate missing snapshot {$snapshotId}.");
        }

        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_active_snapshot (singleton, snapshot_id)
VALUES (1, ?)
ON CONFLICT(singleton) DO UPDATE SET snapshot_id = excluded.snapshot_id
SQL);
        $statement->execute([$snapshotId]);
    }

    public function active(): ?GraphSnapshot
    {
        $id = $this->connection->query(
            'SELECT snapshot_id FROM lm_active_snapshot WHERE singleton = 1',
        )->fetchColumn();

        return is_string($id) ? $this->find($id) : null;
    }

    public function find(string $snapshotId): ?GraphSnapshot
    {
        $statement = $this->connection->prepare('SELECT * FROM lm_snapshots WHERE id = ?');
        $statement->execute([$snapshotId]);
        $snapshot = $statement->fetch();

        if (! is_array($snapshot)) {
            return null;
        }

        $graph = new KnowledgeGraph();
        $nodeStatement = $this->connection->prepare(
            'SELECT * FROM lm_nodes WHERE snapshot_id = ? ORDER BY node_id',
        );
        $nodeStatement->execute([$snapshotId]);

        foreach ($nodeStatement->fetchAll() as $row) {
            $location = $row['file'] === null
                ? null
                : new SourceLocation($row['file'], (int) $row['start_line'], (int) $row['end_line']);
            $graph->addNode(new GraphNode(
                NodeId::fromString($row['node_id']),
                NodeKind::from($row['kind']),
                $row['name'],
                $row['qualified_name'],
                $location,
                $this->decode($row['attributes']),
            ));
        }

        $evidence = $this->loadEvidence($snapshotId);
        $links = $this->loadEvidenceLinks($snapshotId);
        $edgeStatement = $this->connection->prepare(
            'SELECT * FROM lm_edges WHERE snapshot_id = ? ORDER BY edge_id',
        );
        $edgeStatement->execute([$snapshotId]);

        foreach ($edgeStatement->fetchAll() as $row) {
            $records = [];

            foreach ($links[$row['edge_id']] ?? [] as $evidenceId) {
                if (! isset($evidence[$evidenceId])) {
                    throw new RuntimeException("Snapshot {$snapshotId} references missing evidence {$evidenceId}.");
                }

                $records[] = $evidence[$evidenceId];
            }

            $graph->addEdge(new GraphEdge(
                $row['edge_id'],
                NodeId::fromString($row['source_id']),
                NodeId::fromString($row['target_id']),
                EdgeType::from($row['type']),
                $row['site_key'],
                $records,
            ));
        }

        return new GraphSnapshot(
            $snapshot['id'],
            (int) $snapshot['schema_version'],
            $snapshot['analysis_version'],
            new DateTimeImmutable($snapshot['indexed_at']),
            $snapshot['source_fingerprint'],
            $this->loadFiles($snapshotId),
            $graph,
            $this->loadDiagnostics($snapshotId),
            $this->decode($snapshot['phase_metrics']),
            $this->loadProcessSteps($snapshotId),
        );
    }

    public function list(): array
    {
        $ids = $this->connection->query(
            'SELECT id FROM lm_snapshots ORDER BY indexed_at DESC, id ASC',
        )->fetchAll(PDO::FETCH_COLUMN);
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
        $this->connection->exec('DELETE FROM lm_snapshots');
    }

    private function insertSnapshot(GraphSnapshot $snapshot): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_snapshots (
    id, schema_version, analysis_version, indexed_at, source_fingerprint, phase_metrics
) VALUES (?, ?, ?, ?, ?, ?)
SQL);
        $statement->execute([
            $snapshot->id,
            $snapshot->schemaVersion,
            $snapshot->analysisVersion,
            $snapshot->indexedAt->format(DATE_ATOM),
            $snapshot->sourceFingerprint,
            CanonicalJson::encode($snapshot->phaseMetrics),
        ]);
    }

    private function insertFiles(GraphSnapshot $snapshot): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_files (snapshot_id, path, content_hash, size) VALUES (?, ?, ?, ?)
SQL);

        foreach ($snapshot->files as $file) {
            $statement->execute([$snapshot->id, $file->path, $file->contentHash, $file->size]);
        }
    }

    private function insertNodes(GraphSnapshot $snapshot): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_nodes (
    snapshot_id, node_id, kind, name, qualified_name, file, start_line, end_line, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

        foreach ($snapshot->graph->nodes() as $node) {
            $statement->execute([
                $snapshot->id,
                $node->id->value,
                $node->kind->value,
                $node->name,
                $node->qualifiedName,
                $node->location?->file,
                $node->location?->startLine,
                $node->location?->endLine,
                CanonicalJson::encode($node->attributes),
            ]);
        }
    }

    private function insertEvidence(GraphSnapshot $snapshot): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_evidence (
    snapshot_id, evidence_id, origin, detector, certainty, file, start_line, end_line,
    expression, condition_text, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

        foreach ($snapshot->graph->evidence() as $evidence) {
            $statement->execute([
                $snapshot->id,
                $evidence->id(),
                $evidence->origin->value,
                $evidence->detector,
                $evidence->certainty->value,
                $evidence->location?->file,
                $evidence->location?->startLine,
                $evidence->location?->endLine,
                $evidence->expression,
                $evidence->condition,
                CanonicalJson::encode($evidence->attributes),
            ]);
        }
    }

    private function insertEdges(GraphSnapshot $snapshot): void
    {
        $edgeStatement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_edges (
    snapshot_id, edge_id, source_id, target_id, type, site_key
) VALUES (?, ?, ?, ?, ?, ?)
SQL);
        $linkStatement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_edge_evidence (snapshot_id, edge_id, evidence_id) VALUES (?, ?, ?)
SQL);

        foreach ($snapshot->graph->edges() as $edge) {
            $edgeStatement->execute([
                $snapshot->id,
                $edge->id,
                $edge->source->value,
                $edge->target->value,
                $edge->type->value,
                $edge->siteKey,
            ]);

            foreach ($edge->evidence as $evidence) {
                $linkStatement->execute([$snapshot->id, $edge->id, $evidence->id()]);
            }
        }
    }

    private function insertDiagnostics(GraphSnapshot $snapshot): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_diagnostics (
    snapshot_id, code, phase, file, start_line, end_line, message, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL);

        foreach ($snapshot->diagnostics as $diagnostic) {
            $statement->execute([
                $snapshot->id,
                $diagnostic->code->value,
                $diagnostic->phase,
                $diagnostic->file,
                $diagnostic->startLine,
                $diagnostic->endLine,
                $diagnostic->message,
                CanonicalJson::encode($diagnostic->attributes),
            ]);
        }
    }

    private function insertProcessSteps(GraphSnapshot $snapshot): void
    {
        $knownEvidence = array_fill_keys(array_map(
            static fn (EvidenceRecord $record): string => $record->id(),
            $snapshot->graph->evidence(),
        ), true);
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_process_steps (
    snapshot_id, process_id, ordinal, step_id, node_id, step_kind, boundary,
    evidence_ids, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

        foreach ($snapshot->processSteps as $step) {
            foreach ($step->evidenceIds as $evidenceId) {
                if (! isset($knownEvidence[$evidenceId])) {
                    throw new RuntimeException(
                        "Snapshot {$snapshot->id} references missing process-step evidence {$evidenceId}.",
                    );
                }
            }

            $statement->execute([
                $snapshot->id,
                $step->processId->value,
                $step->ordinal,
                $step->stepId,
                $step->nodeId?->value,
                $step->stepKind->value,
                $step->boundary->value,
                CanonicalJson::encode($step->evidenceIds),
                CanonicalJson::encode($step->attributes),
            ]);
        }
    }

    /** @return list<IndexedFile> */
    private function loadFiles(string $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT path, content_hash, size FROM lm_files WHERE snapshot_id = ? ORDER BY path',
        );
        $statement->execute([$snapshotId]);

        return array_map(
            static fn (array $row): IndexedFile => new IndexedFile(
                $row['path'],
                $row['content_hash'],
                (int) $row['size'],
            ),
            $statement->fetchAll(),
        );
    }

    /** @return array<string, EvidenceRecord> */
    private function loadEvidence(string $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM lm_evidence WHERE snapshot_id = ? ORDER BY evidence_id',
        );
        $statement->execute([$snapshotId]);
        $evidence = [];

        foreach ($statement->fetchAll() as $row) {
            $location = $row['file'] === null
                ? null
                : new SourceLocation($row['file'], (int) $row['start_line'], (int) $row['end_line']);
            $record = new EvidenceRecord(
                EvidenceOrigin::from($row['origin']),
                $row['detector'],
                Certainty::from($row['certainty']),
                $location,
                $row['expression'],
                $row['condition_text'],
                $this->decode($row['attributes']),
            );

            if (! hash_equals($row['evidence_id'], $record->id())) {
                throw new RuntimeException("Snapshot {$snapshotId} contains corrupt evidence identity.");
            }

            $evidence[$row['evidence_id']] = $record;
        }

        return $evidence;
    }

    /** @return array<string, list<string>> */
    private function loadEvidenceLinks(string $snapshotId): array
    {
        $statement = $this->connection->prepare(<<<'SQL'
SELECT edge_id, evidence_id
FROM lm_edge_evidence
WHERE snapshot_id = ?
ORDER BY edge_id, evidence_id
SQL);
        $statement->execute([$snapshotId]);
        $links = [];

        foreach ($statement->fetchAll() as $row) {
            $links[$row['edge_id']][] = $row['evidence_id'];
        }

        return $links;
    }

    /** @return list<Diagnostic> */
    private function loadDiagnostics(string $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM lm_diagnostics WHERE snapshot_id = ? ORDER BY id',
        );
        $statement->execute([$snapshotId]);

        return array_map(fn (array $row): Diagnostic => new Diagnostic(
            DiagnosticCode::from($row['code']),
            $row['phase'],
            $row['file'],
            $row['start_line'] === null ? null : (int) $row['start_line'],
            $row['end_line'] === null ? null : (int) $row['end_line'],
            $row['message'],
            $this->decode($row['attributes']),
        ), $statement->fetchAll());
    }

    /** @return list<ProcessStepRecord> */
    private function loadProcessSteps(string $snapshotId): array
    {
        $statement = $this->connection->prepare(<<<'SQL'
SELECT process_id, ordinal, step_id, node_id, step_kind, boundary, evidence_ids, attributes
FROM lm_process_steps
WHERE snapshot_id = ?
ORDER BY process_id, ordinal, step_id
SQL);
        $statement->execute([$snapshotId]);

        return array_map(fn (array $row): ProcessStepRecord => new ProcessStepRecord(
            NodeId::fromString($row['process_id']),
            (int) $row['ordinal'],
            $row['step_id'],
            $row['node_id'] === null ? null : NodeId::fromString($row['node_id']),
            WorkflowStepKind::from($row['step_kind']),
            ExecutionBoundary::from($row['boundary']),
            $this->decode($row['evidence_ids']),
            $this->decode($row['attributes']),
        ), $statement->fetchAll());
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Stored graph JSON must decode to an array.');
        }

        return $decoded;
    }
}
