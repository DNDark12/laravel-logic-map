<?php

namespace DNDark\LogicMap\Analysis\Runtime;

use DateTimeImmutable;

final class RuntimeTraceContext
{
    private ?string $sessionId = null;
    private ?string $snapshotId = null;
    private ?string $correlationId = null;
    private ?string $parentId = null;
    private ?DateTimeImmutable $startedAt = null;

    public function begin(
        string $sessionId,
        string $snapshotId,
        string $correlationId,
        ?string $parentId,
        DateTimeImmutable $startedAt,
    ): void {
        $this->sessionId = $sessionId;
        $this->snapshotId = $snapshotId;
        $this->correlationId = $correlationId;
        $this->parentId = $parentId;
        $this->startedAt = $startedAt;
    }

    public function active(): bool
    {
        return $this->sessionId !== null && $this->snapshotId !== null && $this->correlationId !== null;
    }

    public function sessionId(): ?string { return $this->sessionId; }
    public function snapshotId(): ?string { return $this->snapshotId; }
    public function correlationId(): ?string { return $this->correlationId; }
    public function parentId(): ?string { return $this->parentId; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }

    public function clear(): void
    {
        $this->sessionId = null;
        $this->snapshotId = null;
        $this->correlationId = null;
        $this->parentId = null;
        $this->startedAt = null;
    }
}
