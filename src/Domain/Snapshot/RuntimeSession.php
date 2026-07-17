<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RuntimeSession
{
    public function __construct(
        public string $id,
        public string $snapshotId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public string $rootCorrelationId,
        public int $observationCount = 0,
        public bool $truncated = false,
    ) {
        if (trim($id) === '' || trim($snapshotId) === '' || trim($rootCorrelationId) === '') {
            throw new InvalidArgumentException('Runtime session identity fields are required.');
        }

        if ($endedAt !== null && $endedAt < $startedAt) {
            throw new InvalidArgumentException('Runtime session end time cannot precede its start time.');
        }

        if ($observationCount < 0) {
            throw new InvalidArgumentException('Runtime observation counts cannot be negative.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'snapshot_id' => $this->snapshotId,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'ended_at' => $this->endedAt?->format(DATE_ATOM),
            'root_correlation_id' => $this->rootCorrelationId,
            'observation_count' => $this->observationCount,
            'truncated' => $this->truncated,
        ];
    }
}
