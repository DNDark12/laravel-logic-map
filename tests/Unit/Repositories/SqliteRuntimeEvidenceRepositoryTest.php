<?php

namespace DNDark\LogicMap\Tests\Unit\Repositories;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteRuntimeEvidenceRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteSchema;
use DNDark\LogicMap\Support\CanonicalJson;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteRuntimeEvidenceRepositoryTest extends TestCase
{
    private string $databasePath;
    private SqliteConnectionFactory $factory;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'logic-map-runtime-');
        self::assertIsString($path);
        $this->databasePath = $path;
        $this->factory = new SqliteConnectionFactory($path);
        (new SqliteSchema())->ensure($this->factory->connection());
        $this->now = new DateTimeImmutable('2026-07-17T03:00:00+00:00');
        $this->insertSnapshot('snapshot-a');
        $this->insertSnapshot('snapshot-b');
    }

    protected function tearDown(): void
    {
        unset($this->factory);
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-shm');
        @unlink($this->databasePath.'-wal');
        parent::tearDown();
    }

    public function test_schema_version_columns_indexes_cascade_and_snapshot_isolation(): void
    {
        self::assertSame(2, SqliteSchema::VERSION);
        $pdo = $this->factory->connection();
        self::assertSame(
            ['id', 'snapshot_id', 'started_at', 'ended_at', 'root_correlation_id', 'observation_count', 'truncated'],
            $this->columns($pdo, 'lm_runtime_sessions'),
        );
        self::assertSame(
            ['id', 'session_id', 'correlation_id', 'parent_id', 'observed_at', 'kind', 'source_node_id', 'target_node_id', 'duration_ms', 'success', 'attributes'],
            $this->columns($pdo, 'lm_runtime_observations'),
        );
        self::assertContains('lm_runtime_sessions_snapshot_started', $this->indexes($pdo, 'lm_runtime_sessions'));
        self::assertContains('lm_runtime_observations_session_time', $this->indexes($pdo, 'lm_runtime_observations'));
        self::assertContains('lm_runtime_observations_session_relation', $this->indexes($pdo, 'lm_runtime_observations'));
        self::assertContains('lm_runtime_observations_correlation', $this->indexes($pdo, 'lm_runtime_observations'));

        $repository = $this->repository();
        self::assertTrue($repository->open($this->session('session-a', 'snapshot-a')));
        self::assertTrue($repository->open($this->session('session-b', 'snapshot-b')));
        self::assertTrue($repository->record($this->observation('session-a', 'corr-a', ['method' => 'get', 'status' => 200])));
        self::assertTrue($repository->record($this->observation('session-b', 'corr-b', ['method' => 'post', 'status' => 201])));

        self::assertSame(['corr-a'], array_map(
            static fn (RuntimeObservation $row): string => $row->correlationId,
            $repository->observationsForSnapshot('snapshot-a'),
        ));
        self::assertSame(['corr-b'], array_map(
            static fn (RuntimeObservation $row): string => $row->correlationId,
            $repository->observationsForSnapshot('snapshot-b'),
        ));

        $pdo->exec("DELETE FROM lm_snapshots WHERE id = 'snapshot-a'");
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM lm_runtime_sessions WHERE snapshot_id = 'snapshot-a'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM lm_runtime_observations WHERE session_id = 'session-a'")->fetchColumn());
    }

    public function test_canonical_sanitized_round_trip_and_parent_is_correlation_not_row_id(): void
    {
        $repository = $this->repository();
        $repository->open($this->session('session-a', 'snapshot-a'));
        $observation = new RuntimeObservation(
            'session-a',
            'child-correlation',
            'parent-correlation',
            $this->now,
            'request',
            'route:GET:orders/{order}',
            'method:App\\Services\\OrderService::show',
            8.75,
            true,
            ['status' => 200, 'method' => 'GET', 'password' => 'must-not-survive', 'table_names' => ['orders']],
        );
        self::assertTrue($repository->record($observation));
        $loaded = $repository->observationsForSnapshot('snapshot-a')[0];

        self::assertSame('parent-correlation', $loaded->parentId);
        self::assertSame(['method' => 'GET', 'status' => 200, 'table_names' => ['orders']], $loaded->attributes);
        self::assertSame(
            CanonicalJson::encode($loaded->attributes),
            $this->factory->connection()->query("SELECT attributes FROM lm_runtime_observations WHERE session_id = 'session-a'")->fetchColumn(),
        );
        self::assertSame(1, $repository->session('session-a')?->observationCount);
    }

    public function test_retention_and_session_cap_evict_completed_oldest_first_but_never_active(): void
    {
        $repository = $this->repository(retentionDays: 7, maxSessions: 2);
        $old = $this->session('old-completed', 'snapshot-a', $this->now->modify('-10 days'), $this->now->modify('-9 days'));
        $active = $this->session('active', 'snapshot-a', $this->now->modify('-8 days'));
        self::assertTrue($repository->open($old));
        self::assertTrue($repository->open($active));
        self::assertTrue($repository->open($this->session('new', 'snapshot-a')));
        self::assertNull($repository->session('old-completed'));
        self::assertNotNull($repository->session('active'));
        self::assertNotNull($repository->session('new'));

        $blocked = $this->repository(retentionDays: 7, maxSessions: 2);
        self::assertFalse($blocked->open($this->session('blocked', 'snapshot-a')));
        self::assertSame('runtime_session_capacity_reached', $blocked->diagnostics()[0]['code']);
    }

    public function test_observation_limit_rejects_overflow_and_writes_one_truncation_marker(): void
    {
        $repository = $this->repository(maxObservations: 2);
        $repository->open($this->session('limited', 'snapshot-a'));
        self::assertTrue($repository->record($this->observation('limited', 'corr-1', ['status' => 200])));
        self::assertTrue($repository->record($this->observation('limited', 'corr-2', ['status' => 201])));
        self::assertFalse($repository->record($this->observation('limited', 'corr-3', ['status' => 202])));
        self::assertFalse($repository->record($this->observation('limited', 'corr-4', ['status' => 203])));

        $observations = $repository->observationsForSnapshot('snapshot-a', 'limited');
        self::assertCount(3, $observations);
        self::assertCount(1, array_filter(
            $observations,
            static fn (RuntimeObservation $row): bool => $row->kind === 'diagnostic'
                && ($row->attributes['code'] ?? null) === 'runtime_observation_limit_reached',
        ));
        self::assertTrue($repository->session('limited')?->truncated);
        self::assertSame(3, $repository->session('limited')?->observationCount);
    }

    private function repository(int $retentionDays = 7, int $maxSessions = 1000, int $maxObservations = 5000): SqliteRuntimeEvidenceRepository
    {
        return new SqliteRuntimeEvidenceRepository(
            $this->factory,
            new RuntimeSanitizer(),
            $retentionDays,
            $maxSessions,
            $maxObservations,
            fn (): DateTimeImmutable => $this->now,
        );
    }

    private function session(string $id, string $snapshot, ?DateTimeImmutable $started = null, ?DateTimeImmutable $ended = null): RuntimeSession
    {
        return new RuntimeSession($id, $snapshot, $started ?? $this->now, $ended, 'root-'.$id, 0, false);
    }

    private function observation(string $session, string $correlation, array $attributes): RuntimeObservation
    {
        return new RuntimeObservation($session, $correlation, null, $this->now, 'request', null, null, 1.5, true, $attributes);
    }

    private function insertSnapshot(string $id): void
    {
        $statement = $this->factory->connection()->prepare(
            'INSERT INTO lm_snapshots (id, schema_version, analysis_version, indexed_at, source_fingerprint, phase_metrics) VALUES (?, 2, ?, ?, ?, ?)',
        );
        $statement->execute([$id, '2.0.0-test', $this->now->format(DATE_ATOM), hash('sha256', $id), '{}']);
    }

    private function columns(PDO $pdo, string $table): array
    {
        return array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    }

    private function indexes(PDO $pdo, string $table): array
    {
        return array_column($pdo->query("PRAGMA index_list({$table})")->fetchAll(), 'name');
    }
}
