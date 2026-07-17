<?php

namespace DNDark\LogicMap\Tests\Unit\Repositories;

use DateTimeImmutable;
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
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteGraphRepository;
use DNDark\LogicMap\Support\CanonicalJson;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class SqliteGraphRepositoryTest extends TestCase
{
    private string $databasePath;

    private SqliteConnectionFactory $factory;

    private SqliteGraphRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'logic-map-v2-');
        self::assertIsString($path);
        $this->databasePath = $path;
        $this->factory = new SqliteConnectionFactory($path);
        $this->repository = new SqliteGraphRepository($this->factory);
    }

    protected function tearDown(): void
    {
        unset($this->repository, $this->factory);
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-shm');
        @unlink($this->databasePath.'-wal');
        parent::tearDown();
    }

    public function test_round_trips_the_full_snapshot_and_preserves_multiedges_and_evidence_links(): void
    {
        $snapshot = $this->snapshot('first');
        $this->repository->store($snapshot);

        self::assertNull($this->repository->active(), 'store() must not activate implicitly.');
        $this->repository->activate($snapshot->id);
        $loaded = $this->repository->active();

        self::assertNotNull($loaded);
        self::assertSame($snapshot->id, $loaded->id);
        self::assertSame($snapshot->analysisVersion, $loaded->analysisVersion);
        self::assertEquals($snapshot->indexedAt, $loaded->indexedAt);
        self::assertSame($snapshot->phaseMetrics, $loaded->phaseMetrics);
        self::assertSame(
            array_map(static fn (IndexedFile $file): array => $file->toArray(), $snapshot->files),
            array_map(static fn (IndexedFile $file): array => $file->toArray(), $loaded->files),
        );
        self::assertCount(2, array_filter(
            $loaded->graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->type === EdgeType::Calls,
        ));
        self::assertCount(3, $loaded->graph->evidence());
        self::assertSame(
            array_map(static fn (ProcessStepRecord $step): array => $step->toArray(), $snapshot->processSteps),
            array_map(static fn (ProcessStepRecord $step): array => $step->toArray(), $loaded->processSteps),
        );
        $serviceNode = array_values(array_filter(
            $loaded->graph->nodes(),
            static fn (GraphNode $node): bool => $node->id->value
                === 'method:App\\Services\\OrderService::cancel',
        ))[0];
        self::assertSame(
            ['layer' => 'service', 'nested' => ['a' => 1, 'b' => 2]],
            $serviceNode->attributes,
        );
        self::assertSame($snapshot->diagnostics[0]->toArray(), $loaded->diagnostics[0]->toArray());
        self::assertSame($snapshot->diagnostics[1]->toArray(), $loaded->diagnostics[1]->toArray());

        $pdo = $this->factory->connection();
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM lm_edge_evidence')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM lm_process_steps')->fetchColumn());
        self::assertContains('lm_edge_evidence_by_evidence', $this->indexNames($pdo, 'lm_edge_evidence'));
        self::assertSame('5000', (string) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());

        $edge = $snapshot->graph->edges()[0];
        $evidence = $edge->evidence[0];
        $statement = $pdo->prepare(
            'INSERT INTO lm_edge_evidence (snapshot_id, edge_id, evidence_id) VALUES (?, ?, ?)',
        );

        $this->expectException(PDOException::class);
        $statement->execute([$snapshot->id, $edge->id, $evidence->id()]);
    }

    public function test_snapshot_delete_cascades_owned_rows_and_nullable_diagnostics_round_trip(): void
    {
        $snapshot = $this->snapshot('cascade');
        $this->repository->store($snapshot);
        $pdo = $this->factory->connection();

        self::assertNull($pdo->query(
            'SELECT start_line FROM lm_diagnostics WHERE file IS NULL LIMIT 1',
        )->fetchColumn());

        $delete = $pdo->prepare('DELETE FROM lm_snapshots WHERE id = ?');
        $delete->execute([$snapshot->id]);

        foreach (['lm_files', 'lm_nodes', 'lm_edges', 'lm_evidence', 'lm_edge_evidence', 'lm_diagnostics', 'lm_process_steps'] as $table) {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }

    public function test_activation_is_explicit_and_failed_store_rolls_back_without_replacing_active_snapshot(): void
    {
        $first = $this->snapshot('active');
        $second = $this->snapshot('failing');
        $this->repository->store($first);
        $this->repository->activate($first->id);
        $pdo = $this->factory->connection();
        $quotedId = $pdo->quote($second->id);
        $pdo->exec(<<<SQL
CREATE TRIGGER lm_test_fail_nodes
BEFORE INSERT ON lm_nodes
WHEN NEW.snapshot_id = {$quotedId}
BEGIN
    SELECT RAISE(ABORT, 'forced node failure');
END
SQL);

        try {
            $this->repository->store($second);
            self::fail('The forced write failure should abort store().');
        } catch (PDOException $exception) {
            self::assertStringContainsString('forced node failure', $exception->getMessage());
        }

        self::assertNull($this->repository->find($second->id));
        self::assertSame($first->id, $this->repository->active()?->id);
        self::assertSame([$first->id], array_map(
            static fn (GraphSnapshot $snapshot): string => $snapshot->id,
            $this->repository->list(),
        ));

        $this->repository->clear();
        self::assertNull($this->repository->active());
        self::assertSame([], $this->repository->list());
    }

    public function test_process_step_evidence_must_exist_in_the_snapshot_registry(): void
    {
        $snapshot = $this->snapshot('invalid-process-evidence', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing process-step evidence');
        $this->repository->store($snapshot);
    }

    public function test_process_rows_are_byte_stable_and_enforce_process_and_node_foreign_keys(): void
    {
        $snapshot = $this->snapshot('stable-process');
        $this->repository->store($snapshot);
        $first = CanonicalJson::encode($this->processRows($this->factory->connection(), $snapshot->id));
        $secondPath = tempnam(sys_get_temp_dir(), 'logic-map-v2-process-');
        self::assertIsString($secondPath);

        try {
            $secondFactory = new SqliteConnectionFactory($secondPath);
            $secondRepository = new SqliteGraphRepository($secondFactory);
            $secondRepository->store($snapshot);
            $second = CanonicalJson::encode($this->processRows($secondFactory->connection(), $snapshot->id));
            self::assertSame($first, $second);
        } finally {
            unset($secondRepository, $secondFactory);
            @unlink($secondPath);
            @unlink($secondPath.'-shm');
            @unlink($secondPath.'-wal');
        }

        $step = $snapshot->processSteps[0];
        $statement = $this->factory->connection()->prepare(<<<'SQL'
INSERT INTO lm_process_steps (
    snapshot_id, process_id, ordinal, step_id, node_id, step_kind, boundary,
    evidence_ids, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

        $this->expectException(PDOException::class);
        $statement->execute([
            $snapshot->id,
            $step->processId->value,
            99,
            'step:missing-node',
            'method:App\Missing::run',
            WorkflowStepKind::Symbol->value,
            ExecutionBoundary::Sync->value,
            CanonicalJson::encode([]),
            CanonicalJson::encode([]),
        ]);
    }

    private function snapshot(string $seed, bool $invalidProcessEvidence = false): GraphSnapshot
    {
        $graph = new KnowledgeGraph();
        $source = NodeId::method('App\Services\OrderService', 'cancel');
        $target = NodeId::method('App\Repositories\OrderRepository', 'save');
        $process = NodeId::named(NodeKind::Process, $source->value);
        $graph->addNode(new GraphNode(
            $source,
            NodeKind::Method,
            'cancel',
            'App\Services\OrderService::cancel',
            new SourceLocation('app/Services/OrderService.php', 10, 30),
            ['nested' => ['b' => 2, 'a' => 1], 'layer' => 'service'],
        ));
        $graph->addNode(new GraphNode(
            $target,
            NodeKind::Method,
            'save',
            'App\Repositories\OrderRepository::save',
            new SourceLocation('app/Repositories/OrderRepository.php', 5, 20),
            ['layer' => 'repository'],
        ));
        $graph->addNode(new GraphNode(
            $process,
            NodeKind::Process,
            'OrderService cancel process',
            null,
            null,
            ['entrypoint_id' => $source->value],
        ));

        foreach ([12, 24] as $line) {
            $graph->addEdge(GraphEdge::fromEvidence(
                $source,
                $target,
                EdgeType::Calls,
                new EvidenceRecord(
                    EvidenceOrigin::StaticAst,
                    'call-resolver',
                    Certainty::Probable,
                    new SourceLocation('app/Services/OrderService.php', $line, $line),
                    '$this->orders->save($order)',
                    null,
                    ['line' => $line],
                ),
            ));
        }

        $membershipEvidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'process-membership',
            Certainty::Certain,
            null,
            null,
            null,
            ['registration_key' => $process->value."\0".$source->value],
        );
        $graph->addEdge(GraphEdge::fromEvidence(
            $source,
            $process,
            EdgeType::StepInProcess,
            $membershipEvidence,
        ));

        $fingerprint = hash('sha256', $seed);
        $id = hash('sha256', '1'."\0".$fingerprint);

        return new GraphSnapshot(
            $id,
            1,
            '2.0-core-1',
            new DateTimeImmutable('2026-07-16T01:02:03+00:00'),
            $fingerprint,
            [
                new IndexedFile('routes/web.php', hash('sha256', 'routes-'.$seed), 22),
                new IndexedFile('app/Services/OrderService.php', hash('sha256', 'service-'.$seed), 123),
            ],
            $graph,
            [
                new Diagnostic(
                    DiagnosticCode::AmbiguousTarget,
                    'resolve',
                    'app/Services/OrderService.php',
                    12,
                    12,
                    'Two implementations remain.',
                    ['candidates' => ['A', 'B']],
                ),
                new Diagnostic(
                    DiagnosticCode::RuntimeTraceGap,
                    'runtime',
                    null,
                    null,
                    null,
                    'No trace available.',
                    ['environment' => 'test'],
                ),
            ],
            ['parse' => ['duration_ms' => 10], 'resolve' => ['duration_ms' => 20]],
            [
                new ProcessStepRecord(
                    $process,
                    0,
                    'step:entry',
                    $source,
                    WorkflowStepKind::Entry,
                    ExecutionBoundary::Sync,
                    [$invalidProcessEvidence ? str_repeat('f', 64) : $membershipEvidence->id()],
                    ['label' => 'cancel'],
                ),
                new ProcessStepRecord(
                    $process,
                    1,
                    'decision:cancelable',
                    null,
                    WorkflowStepKind::Decision,
                    ExecutionBoundary::Sync,
                    [$membershipEvidence->id()],
                    ['condition' => '$order->isCancelable()'],
                ),
            ],
        );
    }

    /** @return list<string> */
    private function indexNames(PDO $pdo, string $table): array
    {
        return array_map(
            static fn (array $row): string => $row['name'],
            $pdo->query("PRAGMA index_list('{$table}')")->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function processRows(PDO $pdo, string $snapshotId): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT process_id, ordinal, step_id, node_id, step_kind, boundary, evidence_ids, attributes
FROM lm_process_steps
WHERE snapshot_id = ?
ORDER BY process_id, ordinal, step_id
SQL);
        $statement->execute([$snapshotId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
