<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RuntimeObservation
{
    public function __construct(
        public string $sessionId,
        public string $correlationId,
        public ?string $parentId,
        public DateTimeImmutable $observedAt,
        public string $kind,
        public ?string $sourceNodeId,
        public ?string $targetNodeId,
        public ?float $durationMs,
        public ?bool $success,
        public array $attributes,
    ) {
        if (trim($sessionId) === '' || trim($correlationId) === '' || trim($kind) === '') {
            throw new InvalidArgumentException('Runtime observation session, correlation, and kind are required.');
        }

        if ($parentId !== null && trim($parentId) === '') {
            throw new InvalidArgumentException('Runtime parent correlation IDs cannot be empty.');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new InvalidArgumentException('Runtime durations cannot be negative.');
        }
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'correlation_id' => $this->correlationId,
            'parent_id' => $this->parentId,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'kind' => $this->kind,
            'source_node_id' => $this->sourceNodeId,
            'target_node_id' => $this->targetNodeId,
            'duration_ms' => $this->durationMs,
            'success' => $this->success,
            'attributes' => $this->attributes,
        ];
    }
}
