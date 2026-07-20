<?php

namespace DNDark\LogicMap\Contracts;

use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;

interface SemanticGraphRepository
{
    public function store(GraphSnapshot $snapshot): void;

    public function activate(string $snapshotId): void;

    public function active(): ?GraphSnapshot;

    /** Cheap lookup of the active snapshot id without hydrating the graph. */
    public function activeId(): ?string;

    public function find(string $snapshotId): ?GraphSnapshot;

    /** @return list<GraphSnapshot> */
    public function list(): array;

    public function clear(): void;
}
