<?php

namespace dndark\LogicMap\Domain;

class SnapshotResolution
{
    public function __construct(
        public ?string $requestedFingerprint,
        public ?string $resolvedFingerprint,
        public string $resolvedVia,
        public string $pointerState,
        public string $analysisState = 'not_requested',
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
     * @return array{
     *     requested_snapshot: ?string,
     *     resolved_via: string,
     *     resolved_fingerprint: ?string,
     *     pointer_state: string,
     *     analysis_state: string
     * }
     */
    public function context(): array
    {
        return [
            'requested_snapshot' => $this->requestedFingerprint,
            'resolved_via' => $this->resolvedVia,
            'resolved_fingerprint' => $this->resolvedFingerprint,
            'pointer_state' => $this->pointerState,
            'analysis_state' => $this->analysisState,
        ];
    }
}
