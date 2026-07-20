<?php

namespace DNDark\LogicMap\Repositories\Database;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Support\CanonicalJson;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class DatabaseRuntimeEvidenceRepository implements RuntimeEvidenceRepository
{
    /** @var list<array{code:string,message:string}> */
    private array $diagnostics = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
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
    }

    public function open(RuntimeSession $session): bool
    {
        if ($this->session($session->id) !== null) {
            throw new InvalidArgumentException("Runtime session {$session->id} already exists.");
        }

        $this->pruneExpired();
        $this->enforceSessionCap();

        if ($this->connection->table('lm_runtime_sessions')->count() >= $this->maxSessions) {
            $this->diagnose('runtime_session_capacity_reached', 'Runtime evidence session capacity is full; collection was skipped.');

            return false;
        }

        $this->connection->table('lm_runtime_sessions')->insert([
            'id' => $session->id,
            'snapshot_id' => $session->snapshotId,
            'started_at' => $session->startedAt->format(DATE_ATOM),
            'ended_at' => $session->endedAt?->format(DATE_ATOM),
            'root_correlation_id' => $session->rootCorrelationId,
            'observation_count' => $session->observationCount,
            'truncated' => $session->truncated,
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

        $this->connection->table('lm_runtime_sessions')
            ->where('id', $sessionId)
            ->update(['ended_at' => $endedAt->format(DATE_ATOM)]);
    }

    public function record(RuntimeObservation $observation): bool
    {
        $session = $this->session($observation->sessionId);

        if ($session === null) {
            throw new InvalidArgumentException("Runtime session {$observation->sessionId} does not exist.");
        }

        if ($session->observationCount >= $this->maxObservationsPerSession) {
            if (! $session->truncated) {
                $this->insertObservation(new RuntimeObservation(
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

        $this->insertObservation($observation);
        $this->increment($observation->sessionId, false);

        return true;
    }

    public function session(string $sessionId): ?RuntimeSession
    {
        $row = $this->connection->table('lm_runtime_sessions')->where('id', $sessionId)->first();

        return $row === null ? null : $this->hydrateSession($row);
    }

    public function sessionsForSnapshot(string $snapshotId): array
    {
        return $this->connection->table('lm_runtime_sessions')
            ->where('snapshot_id', $snapshotId)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map($this->hydrateSession(...))
            ->all();
    }

    public function observationsForSnapshot(string $snapshotId, ?string $sessionId = null): array
    {
        $query = $this->connection->table('lm_runtime_observations as o')
            ->join('lm_runtime_sessions as s', 's.id', '=', 'o.session_id')
            ->where('s.snapshot_id', $snapshotId);

        if ($sessionId !== null) {
            $query->where('s.id', $sessionId);
        }

        return $query->orderBy('o.observed_at')
            ->orderBy('o.id')
            ->get(['o.*'])
            ->map($this->hydrateObservation(...))
            ->all();
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
        $this->connection->table('lm_runtime_observations')->delete();
        $this->connection->table('lm_runtime_sessions')->delete();
    }

    private function insertObservation(RuntimeObservation $observation): void
    {
        $attributes = $this->sanitizer->sanitize($observation->attributes);

        $this->connection->table('lm_runtime_observations')->insert([
            'session_id' => $observation->sessionId,
            'correlation_id' => $observation->correlationId,
            'parent_id' => $observation->parentId,
            'observed_at' => $observation->observedAt->format(DATE_ATOM),
            'kind' => $observation->kind,
            'source_node_id' => $observation->sourceNodeId,
            'target_node_id' => $observation->targetNodeId,
            'duration_ms' => $observation->durationMs,
            'success' => $observation->success,
            'attributes' => CanonicalJson::encode($attributes),
        ]);
    }

    private function increment(string $sessionId, bool $truncated): void
    {
        $query = $this->connection->table('lm_runtime_sessions')->where('id', $sessionId);

        if ($truncated) {
            $query->update([
                'observation_count' => $this->connection->raw('observation_count + 1'),
                'truncated' => true,
            ]);
        } else {
            $query->increment('observation_count');
        }
    }

    private function pruneExpired(): void
    {
        $cutoff = $this->now()->modify('-'.$this->retentionDays.' days')->format(DATE_ATOM);
        $ids = $this->connection->table('lm_runtime_sessions')
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->orderBy('ended_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($this->cleanupBatch)
            ->pluck('id')
            ->all();
        $this->deleteSessions($ids);
    }

    private function enforceSessionCap(): void
    {
        $count = $this->connection->table('lm_runtime_sessions')->count();
        $needed = max(0, $count - $this->maxSessions + 1);

        if ($needed === 0) {
            return;
        }

        $ids = $this->connection->table('lm_runtime_sessions')
            ->whereNotNull('ended_at')
            ->orderBy('ended_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit(min($needed, $this->cleanupBatch))
            ->pluck('id')
            ->all();
        $this->deleteSessions($ids);
    }

    /** @param list<string> $ids */
    private function deleteSessions(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection->table('lm_runtime_observations')->whereIn('session_id', $ids)->delete();
        $this->connection->table('lm_runtime_sessions')->whereIn('id', $ids)->delete();
    }

    private function hydrateSession(object $row): RuntimeSession
    {
        return new RuntimeSession(
            $row->id,
            $row->snapshot_id,
            new DateTimeImmutable($row->started_at),
            $row->ended_at === null ? null : new DateTimeImmutable($row->ended_at),
            $row->root_correlation_id,
            (int) $row->observation_count,
            (bool) $row->truncated,
        );
    }

    private function hydrateObservation(object $row): RuntimeObservation
    {
        $attributes = json_decode($row->attributes, true, 512, JSON_THROW_ON_ERROR);

        return new RuntimeObservation(
            $row->session_id,
            $row->correlation_id,
            $row->parent_id,
            new DateTimeImmutable($row->observed_at),
            $row->kind,
            $row->source_node_id,
            $row->target_node_id,
            $row->duration_ms === null ? null : (float) $row->duration_ms,
            $row->success === null ? null : (bool) $row->success,
            is_array($attributes) ? $attributes : [],
        );
    }

    private function now(): DateTimeImmutable
    {
        return is_callable($this->clock) ? ($this->clock)() : new DateTimeImmutable('now');
    }
}
