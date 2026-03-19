<?php

namespace dndark\LogicMap\Contracts;

use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;

interface GraphRepository
{
    /**
     * Get the graph snapshot by fingerprint.
     *
     * @param string $fingerprint
     * @return Graph|null
     */
    public function getSnapshot(string $fingerprint): ?Graph;

    /**
     * Get the latest snapshot regardless of fingerprint.
     *
     * @return Graph|null
     */
    public function getLatestSnapshot(): ?Graph;

    /**
     * Store the graph snapshot with a fingerprint.
     *
     * @param string $fingerprint
     * @param Graph $graph
     * @return void
     */
    public function putSnapshot(string $fingerprint, Graph $graph): void;

    /**
     * Store analysis report separately from graph snapshot (ADR-011).
     * Uses compound key: {graphFingerprint}.{analysisConfigHash}
     */
    public function putAnalysisReport(string $fingerprint, AnalysisReport $report): void;

    /**
     * Get analysis report for a given graph fingerprint.
     * Optionally matches analysis config hash for cache validity.
     */
    public function getAnalysisReport(string $fingerprint, ?string $configHash = null): ?AnalysisReport;

    /**
     * Clear all cached snapshots and projections.
     *
     * @return int Number of snapshots cleared
     */
    public function clear(): int;

    /**
     * Check if a snapshot exists.
     *
     * @param string $fingerprint
     * @return bool
     */
    public function hasSnapshot(string $fingerprint): bool;

    /**
     * List available snapshot fingerprints in chronological insertion order.
     *
     * @return array<int, string>
     */
    public function listFingerprints(): array;

    /**
     * Get latest available fingerprint pointer.
     */
    public function getLatestFingerprint(): ?string;
}
