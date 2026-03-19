<?php

namespace dndark\LogicMap\Domain;

class SnapshotResolution
{
    public function __construct(
        public ?string $requestedFingerprint,
        public ?string $resolvedFingerprint,
        public string $resolvedVia,
        public string $pointerState,
        public ?Graph $graph = null,
        public ?AnalysisReport $analysis = null,
    ) {
    }

    public function hasGraph(): bool
    {
        return $this->graph instanceof Graph;
    }

    public function hasAnalysis(): bool
    {
        return $this->analysis instanceof AnalysisReport;
    }

    /**
     * @return array{resolved_via: string, resolved_fingerprint: ?string, pointer_state: string}
     */
    public function context(): array
    {
        return [
            'resolved_via' => $this->resolvedVia,
            'resolved_fingerprint' => $this->resolvedFingerprint,
            'pointer_state' => $this->pointerState,
        ];
    }
}
