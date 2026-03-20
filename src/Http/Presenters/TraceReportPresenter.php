<?php

namespace dndark\LogicMap\Http\Presenters;

use dndark\LogicMap\Support\HumanLabelResolver;
use dndark\LogicMap\Support\ReasonCopyFormatter;
use Illuminate\Contracts\Support\Arrayable;

class TraceReportPresenter implements Arrayable
{
    public function __construct(
        public readonly array $data,
        public readonly string $snapshotFingerprint,
    ) {
    }

    public function toArray(): array
    {
        $segments    = $this->data['segments'] ?? [];
        $branchPts   = $this->data['branch_points'] ?? [];
        $persistence = $this->data['persistence_touchpoints'] ?? [];
        $asyncBounds = $this->extractAsyncBoundaries($segments);

        return [
            'targetName'      => $this->data['target']['name'] ?? 'Unknown Workflow',
            'targetNodeId'    => $this->data['target']['node_id'] ?? '',
            'targetKindLabel' => HumanLabelResolver::formatKind($this->data['target']['kind'] ?? 'unknown'),
            'summary'         => $this->data['summary'] ?? [],
            'humanSummary'    => $this->data['human_summary'] ?? null,
            // Enriched timeline segments
            'segments'        => $this->enrichSegments($segments),
            'entrypoints'     => $this->data['entrypoints'] ?? [],
            'branchPoints'    => $this->enrichBranchPoints($branchPts),
            'persistence'     => $this->enrichPersistence($persistence),
            'asyncBoundaries' => $asyncBounds,
            'snapshot'        => $this->snapshotFingerprint,
            // PM Stats
            'statMainSteps'       => count($segments),
            'statBranchCount'     => count($branchPts),
            'statAsyncCount'      => count($asyncBounds),
            'statPersistCount'    => count($persistence),
            'rawData'             => $this->data,
        ];
    }

    private function enrichSegments(array $segments): array
    {
        return array_map(function (array $seg) {
            $seg['is_async']       = isset($seg['edge_type']) && str_contains(strtolower($seg['edge_type'] ?? ''), 'dispatch');
            $seg['is_persistence'] = isset($seg['to_kind']) && in_array($seg['to_kind'] ?? '', ['model', 'repository'], true);
            $seg['step_badge']     = $seg['is_async'] ? 'async' : ($seg['is_persistence'] ? 'persistence' : 'sync');
            $seg['from_label']     = $seg['from_name'] ?? $this->abbreviate($seg['from_node_id'] ?? '');
            $seg['to_label']       = $seg['to_name'] ?? $this->abbreviate($seg['to_node_id'] ?? '');
            return $seg;
        }, $segments);
    }

    private function enrichBranchPoints(array $branches): array
    {
        return array_map(function (array $bp) {
            $bp['kind_label']   = HumanLabelResolver::formatKind($bp['kind'] ?? 'unknown');
            $bp['branch_names'] = array_map(
                fn($b) => $b['name'] ?? $this->abbreviate($b['target_id'] ?? ''),
                $bp['branches'] ?? []
            );
            return $bp;
        }, $branches);
    }

    private function enrichPersistence(array $points): array
    {
        return array_map(function (array $tp) {
            $tp['kind_label'] = HumanLabelResolver::formatKind($tp['kind'] ?? 'unknown');
            return $tp;
        }, $points);
    }

    private function extractAsyncBoundaries(array $segments): array
    {
        return array_values(array_filter($segments, function ($seg) {
            return str_contains(strtolower($seg['edge_type'] ?? ''), 'dispatch') ||
                   str_contains(strtolower($seg['segment_type'] ?? ''), 'async');
        }));
    }

    private function abbreviate(string $nodeId): string
    {
        // Return just the class@method part if full FQCN
        if (str_contains($nodeId, '\\')) {
            $parts = explode('\\', $nodeId);
            return end($parts);
        }
        return $nodeId;
    }
}
