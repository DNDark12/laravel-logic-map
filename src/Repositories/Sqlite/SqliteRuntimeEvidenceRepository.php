<?php

namespace DNDark\LogicMap\Repositories\Sqlite;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Support\CanonicalJson;
use InvalidArgumentException;
use PDO;

final class SqliteRuntimeEvidenceRepository implements RuntimeEvidenceRepository
{
    private readonly PDO $connection;

    /** @var list<array{code:string,message:string}> */
    private array $diagnostics = [];

    public function __construct(
        SqliteConnectionFactory $factory,
        private readonly RuntimeSanitizer $sanitizer,
        private readonly int $retentionDays = 7,
        private readonly int $maxSessions = 1000,
        private readonly int $maxObservationsPerSession = 5000,
        private readonly mixed $clock = null,
        private readonly int $cleanupBatch = 100,
    ) {
        if ($retentionDays < 1 || $maxSessions < 1 || $maxObservationsPerSession < 1 || $cleanupBatch < 1) {
            throw new InvalidArgumentException('Runtime evidence storage limits must be positive.');
        }

        $this->connection = $factory->connection();
        (new SqliteSchema())->ensure($this->connection);
    }

    public function open(RuntimeSession $session): bool
    {
        if ($this->session($session->id) !== null) {
            throw new InvalidArgumentException("Runtime session {$session->id} already exists.");
        }

        $this->pruneExpired();
        $this->enforceSessionCap();

        if ((int) $this->connection->query('SELECT COUNT(*) FROM lm_runtime_sessions')->fetchColumn() >= $this->maxSessions) {
            $this->diagnose('runtime_session_capacity_reached', 'Runtime evidence session capacity is full; collection was skipped.');

            return false;
        }

        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_runtime_sessions (
    id, snapshot_id, started_at, ended_at, root_correlation_id, observation_count, truncated
) VALUES (?, ?, ?, ?, ?, ?, ?)
SQL);
        $statement->execute([
            $session->id,
            $session->snapshotId,
            $session->startedAt->format(DATE_ATOM),
            $session->endedAt?->format(DATE_ATOM),
            $session->rootCorrelationId,
            $session->observationCount,
            $session->truncated ? 1 : 0,
        ]);

        return true;
    }

    public function complete(string $sessionId, DateTimeImmutable $endedAt): void
    {
        $session = $this->session($sessionId);

        if ($session === null) {
            throw new InvalidArgumentException("Runtime session {$sessionId} does not exist.");
        }

        if ($endedAt < $session->startedAt) {
            throw new InvalidArgumentException('Runtime session end time cannot precede its start time.');
        }

        $statement = $this->connection->prepare('UPDATE lm_runtime_sessions SET ended_at = ? WHERE id = ?');
        $statement->execute([$endedAt->format(DATE_ATOM), $sessionId]);
    }

    public function record(RuntimeObservation $observation): bool
    {
        $session = $this->session($observation->sessionId);

        if ($session === null) {
            throw new InvalidArgumentException("Runtime session {$observation->sessionId} does not exist.");
        }

        if ($session->observationCount >= $this->maxObservationsPerSession) {
            if (! $session->truncated) {
                $this->insert(new RuntimeObservation(
                    $session->id,
                    'runtime-limit:'.$session->id,
                    null,
                    $this->now(),
                    'diagnostic',
                    null,
                    null,
                    null,
                    false,
                    [
                        'code' => 'runtime_observation_limit_reached',
                        'message' => 'Runtime observation limit reached; further observations were discarded.',
                    ],
                ));
                $this->increment($session->id, true);
            }

            return false;
        }

        $this->insert($observation);
        $this->increment($session->id, false);

        return true;
    }

    public function session(string $sessionId): ?RuntimeSession
    {
        $statement = $this->connection->prepare('SELECT * FROM lm_runtime_sessions WHERE id = ?');
        $statement->execute([$sessionId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateSession($row) : null;
    }

    public function sessionsForSnapshot(string $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM lm_runtime_sessions WHERE snapshot_id = ? ORDER BY started_at, id',
        );
        $statement->execute([$snapshotId]);

        return array_map($this->hydrateSession(...), $statement->fetchAll());
    }

    public function observationsForSnapshot(string $snapshotId, ?string $sessionId = null): array
    {
        $sql = <<<'SQL'
SELECT o.*
FROM lm_runtime_observations o
INNER JOIN lm_runtime_sessions s ON s.id = o.session_id
WHERE s.snapshot_id = ?
SQL;
        $parameters = [$snapshotId];

        if ($sessionId !== null) {
            $sql .= ' AND s.id = ?';
            $parameters[] = $sessionId;
        }

        $sql .= ' ORDER BY o.observed_at, o.id';
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return array_map($this->hydrateObservation(...), $statement->fetchAll());
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function diagnose(string $code, string $message): void
    {
        if (count($this->diagnostics) >= 50) {
            return;
        }

        $this->diagnostics[] = [
            'code' => substr($code, 0, 100),
            'message' => substr($message, 0, 500),
        ];
    }

    public function clear(): void
    {
        $this->connection->exec('DELETE FROM lm_runtime_sessions');
    }

    private function insert(RuntimeObservation $observation): void
    {
        $attributes = $this->sanitizer->sanitize($observation->attributes);
        $statement = $this->connection->prepare(<<<'SQL'
INSERT INTO lm_runtime_observations (
    session_id, correlation_id, parent_id, observed_at, kind, source_node_id,
    target_node_id, duration_ms, success, attributes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $statement->execute([
            $observation->sessionId,
            $observation->correlationId,
            $observation->parentId,
            $observation->observedAt->format(DATE_ATOM),
            $observation->kind,
            $observation->sourceNodeId,
            $observation->targetNodeId,
            $observation->durationMs,
            $observation->success === null ? null : ($observation->success ? 1 : 0),
            CanonicalJson::encode($attributes),
        ]);
    }

    private function increment(string $sessionId, bool $truncated): void
    {
        $sql = $truncated
            ? 'UPDATE lm_runtime_sessions SET observation_count = observation_count + 1, truncated = 1 WHERE id = ?'
            : 'UPDATE lm_runtime_sessions SET observation_count = observation_count + 1 WHERE id = ?';
        $statement = $this->connection->prepare($sql);
        $statement->execute([$sessionId]);
    }

    private function pruneExpired(): void
    {
        $cutoff = $this->now()->modify('-'.$this->retentionDays.' days')->format(DATE_ATOM);
        $statement = $this->connection->prepare(<<<'SQL'
SELECT id FROM lm_runtime_sessions
WHERE ended_at IS NOT NULL AND ended_at < ?
ORDER BY ended_at, started_at, id
LIMIT ?
SQL);
        $statement->bindValue(1, $cutoff);
        $statement->bindValue(2, $this->cleanupBatch, PDO::PARAM_INT);
        $statement->execute();
        $this->deleteSessions($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function enforceSessionCap(): void
    {
        $count = (int) $this->connection->query('SELECT COUNT(*) FROM lm_runtime_sessions')->fetchColumn();
        $needed = max(0, $count - $this->maxSessions + 1);

        if ($needed === 0) {
            return;
        }

        $limit = min($needed, $this->cleanupBatch);
        $statement = $this->connection->prepare(<<<'SQL'
SELECT id FROM lm_runtime_sessions
WHERE ended_at IS NOT NULL
ORDER BY ended_at, started_at, id
LIMIT ?
SQL);
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        $this->deleteSessions($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function deleteSessions(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $statement = $this->connection->prepare(
            'DELETE FROM lm_runtime_sessions WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
        );
        $statement->execute(array_values($ids));
    }

    private function hydrateSession(array $row): RuntimeSession
    {
        return new RuntimeSession(
            $row['id'],
            $row['snapshot_id'],
            new DateTimeImmutable($row['started_at']),
            $row['ended_at'] === null ? null : new DateTimeImmutable($row['ended_at']),
            $row['root_correlation_id'],
            (int) $row['observation_count'],
            (bool) $row['truncated'],
        );
    }

    private function hydrateObservation(array $row): RuntimeObservation
    {
        $attributes = json_decode($row['attributes'], true, 512, JSON_THROW_ON_ERROR);

        return new RuntimeObservation(
            $row['session_id'],
            $row['correlation_id'],
            $row['parent_id'],
            new DateTimeImmutable($row['observed_at']),
            $row['kind'],
            $row['source_node_id'],
            $row['target_node_id'],
            $row['duration_ms'] === null ? null : (float) $row['duration_ms'],
            $row['success'] === null ? null : (bool) $row['success'],
            is_array($attributes) ? $attributes : [],
        );
    }

    private function now(): DateTimeImmutable
    {
        return is_callable($this->clock) ? ($this->clock)() : new DateTimeImmutable('now');
    }

}
