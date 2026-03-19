<?php

namespace dndark\LogicMap\Repositories;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use Illuminate\Support\Facades\Cache;

class CacheGraphRepository implements GraphRepository
{
    protected const REGISTRY_KEY = 'logic-map.fingerprint_registry';
    protected const LATEST_KEY = 'logic-map.latest_fingerprint';
    protected const ANALYSIS_REGISTRY_KEY = 'logic-map.analysis_registry';

    public function getSnapshot(string $fingerprint): ?Graph
    {
        $data = Cache::get($this->getSnapshotKey($fingerprint));

        if (!$data) {
            return null;
        }

        return Graph::fromArray($data);
    }

    public function getLatestSnapshot(): ?Graph
    {
        $fingerprint = $this->getLatestFingerprint();

        if (!$fingerprint) {
            return null;
        }

        return $this->getSnapshot($fingerprint);
    }

    public function putSnapshot(string $fingerprint, Graph $graph): void
    {
        $ttl = config('logic-map.cache_ttl', 3600);

        Cache::put(
            $this->getSnapshotKey($fingerprint),
            $graph->toArray(),
            $ttl
        );

        // Track fingerprint in registry for 'clear' command
        $registry = Cache::get(self::REGISTRY_KEY, []);
        if (!in_array($fingerprint, $registry)) {
            $registry[] = $fingerprint;
            Cache::put(self::REGISTRY_KEY, $registry, $ttl);
        }

        // Store latest fingerprint pointer
        Cache::put(self::LATEST_KEY, $fingerprint, $ttl);
        Cache::put($this->getConfiguredFingerprintKey(), $fingerprint, $ttl);
    }

    /**
     * Store analysis report with compound key: {fingerprint}.{configHash} (ADR-011)
     */
    public function putAnalysisReport(string $fingerprint, AnalysisReport $report): void
    {
        $ttl = config('logic-map.cache_ttl', 3600);
        $configHash = $report->metadata['analysis_config_hash'] ?? 'default';
        $key = $this->getAnalysisKey($fingerprint, $configHash);

        Cache::put($key, $report->toArray(), $ttl);

        // Track analysis keys for cleanup
        $registry = Cache::get(self::ANALYSIS_REGISTRY_KEY, []);
        if (!in_array($key, $registry)) {
            $registry[] = $key;
            Cache::put(self::ANALYSIS_REGISTRY_KEY, $registry, $ttl);
        }
    }

    /**
     * Get analysis report. If configHash is provided, requires exact match.
     * If not, returns latest analysis for this fingerprint.
     */
    public function getAnalysisReport(string $fingerprint, ?string $configHash = null): ?AnalysisReport
    {
        if ($configHash) {
            $data = Cache::get($this->getAnalysisKey($fingerprint, $configHash));

            if ($data) {
                return AnalysisReport::fromArray($data);
            }

            return null;
        }

        // Fallback: search analysis registry for any matching fingerprint
        $registry = Cache::get(self::ANALYSIS_REGISTRY_KEY, []);
        $prefix = $this->getAnalysisKeyPrefix($fingerprint);

        foreach ($registry as $key) {
            if (str_starts_with($key, $prefix)) {
                $data = Cache::get($key);
                if ($data) {
                    return AnalysisReport::fromArray($data);
                }
            }
        }

        return null;
    }

    /**
     * Clear all cached snapshots AND analysis reports (INV-S4-08).
     */
    public function clear(): int
    {
        $cleared = 0;

        // Clear graph snapshots
        $fingerprints = Cache::get(self::REGISTRY_KEY, []);
        foreach ($fingerprints as $fp) {
            if (Cache::forget($this->getSnapshotKey($fp))) {
                $cleared++;
            }
        }
        Cache::forget(self::REGISTRY_KEY);
        Cache::forget(self::LATEST_KEY);
        Cache::forget($this->getConfiguredFingerprintKey());

        // Clear analysis reports
        $analysisKeys = Cache::get(self::ANALYSIS_REGISTRY_KEY, []);
        foreach ($analysisKeys as $key) {
            if (Cache::forget($key)) {
                $cleared++;
            }
        }
        Cache::forget(self::ANALYSIS_REGISTRY_KEY);

        return $cleared;
    }

    public function hasSnapshot(string $fingerprint): bool
    {
        return Cache::has($this->getSnapshotKey($fingerprint));
    }

    public function listFingerprints(): array
    {
        $registry = Cache::get(self::REGISTRY_KEY, []);
        if (!is_array($registry)) {
            return [];
        }

        $valid = [];
        foreach ($registry as $fingerprint) {
            if (!is_string($fingerprint) || $fingerprint === '') {
                continue;
            }

            if ($this->hasSnapshot($fingerprint)) {
                $valid[] = $fingerprint;
            }
        }

        return array_values(array_unique($valid));
    }

    public function getLatestFingerprint(): ?string
    {
        $fingerprint = Cache::get($this->getConfiguredFingerprintKey());
        if (!is_string($fingerprint) || $fingerprint === '') {
            $fingerprint = Cache::get(self::LATEST_KEY);
        }

        if (!is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        return $this->hasSnapshot($fingerprint) ? $fingerprint : null;
    }

    protected function getConfiguredFingerprintKey(): string
    {
        $key = config('logic-map.fingerprint_key', 'logic_map.fingerprint');
        return is_string($key) && $key !== '' ? $key : 'logic_map.fingerprint';
    }

    protected function getSnapshotKey(string $fingerprint): string
    {
        return config('logic-map.cache_key', 'dndark.logic_map.snapshot') . '.' . $fingerprint;
    }

    protected function getAnalysisKey(string $fingerprint, string $configHash): string
    {
        return config('logic-map.analysis_cache_key', 'dndark.logic_map.analysis')
            . '.' . $fingerprint
            . '.' . $configHash;
    }

    protected function getAnalysisKeyPrefix(string $fingerprint): string
    {
        return config('logic-map.analysis_cache_key', 'dndark.logic_map.analysis')
            . '.' . $fingerprint . '.';
    }
}
