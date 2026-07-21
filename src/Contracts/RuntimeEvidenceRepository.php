<?php

namespace DNDark\LogicMap\Contracts;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;

interface RuntimeEvidenceRepository
{
    public function open(RuntimeSession $session): bool;

    public function complete(string $sessionId, DateTimeImmutable $endedAt): void;

    public function record(RuntimeObservation $observation): bool;

    public function session(string $sessionId): ?RuntimeSession;

    /** @return list<RuntimeSession> */
    public function sessionsForSnapshot(string $snapshotId): array;

    /** @return list<RuntimeObservation> */
    public function observationsForSnapshot(string $snapshotId, ?string $sessionId = null): array;

    /** @return list<array{code:string,message:string}> */
    public function diagnostics(): array;

    public function diagnose(string $code, string $message): void;

    public function clear(): void;
}
