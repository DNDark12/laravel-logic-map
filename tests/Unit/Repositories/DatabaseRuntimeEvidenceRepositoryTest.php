<?php

namespace DNDark\LogicMap\Tests\Unit\Repositories;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Repositories\Database\DatabaseRuntimeEvidenceRepository;
use DNDark\LogicMap\Support\CanonicalJson;
use DNDark\LogicMap\Support\SchemaVersion;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use PDO;

final class DatabaseRuntimeEvidenceRepositoryTest extends TestCase
{
    private ConnectionInterface $connection;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->app->make('db')->connection();
        $this->now = new DateTimeImmutable('2026-07-17T03:00:00+00:00');
        $this->insertSnapshot('snapshot-a');
        $this->insertSnapshot('snapshot-b');
    }

    public function test_schema_version_columns_indexes_and_snapshot_isolation(): void
    {
        self::assertSame(2, SchemaVersion::VERSION);
        $pdo = $this->connection->getPdo();
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
        self::assertContains('lm_runtime_observations_relation', $this->indexes($pdo, 'lm_runtime_observations'));
        self::assertContains('lm_runtime_observations_correlation', $this->indexes($pdo, 'lm_runtime_observations'));

        $repository = $this->repository();
        self::assertTrue($repository->open($this->runtimeSession('session-a', 'snapshot-a')));
        self::assertTrue($repository->open($this->runtimeSession('session-b', 'snapshot-b')));
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

        $repository->clear();
        self::assertSame(0, $this->connection->table('lm_runtime_sessions')->count());
        self::assertSame(0, $this->connection->table('lm_runtime_observations')->count());
    }

    public function test_canonical_sanitized_round_trip_and_parent_is_correlation_not_row_id(): void
    {
        $repository = $this->repository();
        $repository->open($this->runtimeSession('session-a', 'snapshot-a'));
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
            $this->connection->table('lm_runtime_observations')->where('session_id', 'session-a')->value('attributes'),
        );
        self::assertSame(1, $repository->session('session-a')?->observationCount);
    }

    public function test_retention_and_session_cap_evict_completed_oldest_first_but_never_active(): void
    {
        $repository = $this->repository(retentionDays: 7, maxSessions: 2);
        $old = $this->runtimeSession('old-completed', 'snapshot-a', $this->now->modify('-10 days'), $this->now->modify('-9 days'));
        $active = $this->runtimeSession('active', 'snapshot-a', $this->now->modify('-8 days'));
        self::assertTrue($repository->open($old));
        self::assertTrue($repository->open($active));
        self::assertTrue($repository->open($this->runtimeSession('new', 'snapshot-a')));
        self::assertNull($repository->session('old-completed'));
        self::assertNotNull($repository->session('active'));
        self::assertNotNull($repository->session('new'));

        $blocked = $this->repository(retentionDays: 7, maxSessions: 2);
        self::assertFalse($blocked->open($this->runtimeSession('blocked', 'snapshot-a')));
        self::assertSame('runtime_session_capacity_reached', $blocked->diagnostics()[0]['code']);
    }

    public function test_observation_limit_rejects_overflow_and_writes_one_truncation_marker(): void
    {
        $repository = $this->repository(maxObservations: 2);
        $repository->open($this->runtimeSession('limited', 'snapshot-a'));
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

    private function repository(int $retentionDays = 7, int $maxSessions = 1000, int $maxObservations = 5000): DatabaseRuntimeEvidenceRepository
    {
        return new DatabaseRuntimeEvidenceRepository(
            $this->connection,
            new RuntimeSanitizer(),
            $retentionDays,
            $maxSessions,
            $maxObservations,
            fn (): DateTimeImmutable => $this->now,
        );
    }

    private function runtimeSession(string $id, string $snapshot, ?DateTimeImmutable $started = null, ?DateTimeImmutable $ended = null): RuntimeSession
    {
        return new RuntimeSession($id, $snapshot, $started ?? $this->now, $ended, 'root-'.$id, 0, false);
    }

    private function observation(string $session, string $correlation, array $attributes): RuntimeObservation
    {
        return new RuntimeObservation($session, $correlation, null, $this->now, 'request', null, null, 1.5, true, $attributes);
    }

    private function insertSnapshot(string $id): void
    {
        $this->connection->table('lm_snapshots')->insert([
            'id' => $id,
            'schema_version' => 2,
            'analysis_version' => '2.0.0-test',
            'indexed_at' => $this->now->format(DATE_ATOM),
            'source_fingerprint' => hash('sha256', $id),
            'phase_metrics' => '{}',
        ]);
    }

    private function columns(PDO $pdo, string $table): array
    {
        return array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private function indexes(PDO $pdo, string $table): array
    {
        return array_column($pdo->query("PRAGMA index_list({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
