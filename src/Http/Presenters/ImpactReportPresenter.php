<?php

namespace dndark\LogicMap\Http\Presenters;

use dndark\LogicMap\Support\HumanLabelResolver;
use dndark\LogicMap\Support\RiskCopyFormatter;
use dndark\LogicMap\Support\ReasonCopyFormatter;
use Illuminate\Contracts\Support\Arrayable;

class ImpactReportPresenter implements Arrayable
{
    public function __construct(
        public readonly array $data,
        public readonly string $snapshotFingerprint,
    ) {
    }

    public function toArray(): array
    {
        $riskBucket = strtolower($this->data['summary']['risk_bucket'] ?? 'none');

        return [
            'targetName'       => $this->data['target']['name'] ?? 'Unknown Component',
            'targetNodeId'     => $this->data['target']['node_id'] ?? '',
            'targetKindLabel'  => HumanLabelResolver::formatKind($this->data['target']['kind'] ?? 'unknown'),
            'blastRadius'      => $this->data['summary']['blast_radius_score'] ?? 0,
            'riskBucket'       => ucfirst($riskBucket),
            'riskBadgeClass'   => $this->getRiskBadgeClass($riskBucket),
            'riskExplanation'  => RiskCopyFormatter::format($riskBucket, $this->data['target']['kind'] ?? null),
            'summary'          => $this->data['summary'] ?? [],
            'humanSummary'     => $this->data['human_summary'] ?? null,
            'criticalTouches'  => $this->data['critical_touches'] ?? [],
            'mustReview'       => $this->enrichRows($this->data['review_scope']['must_review'] ?? []),
            'shouldReview'     => $this->enrichRows($this->data['review_scope']['should_review'] ?? []),
            'testFocus'        => $this->enrichRows($this->data['review_scope']['test_focus'] ?? []),
            'snapshot'         => $this->snapshotFingerprint,
            // PM-FriendlyStats
            'statAreasAffected'    => count($this->data['review_scope']['must_review'] ?? []) + count($this->data['review_scope']['should_review'] ?? []),
            'statIncoming'         => $this->data['summary']['upstream_count'] ?? 0,
            'statDownstream'       => $this->data['summary']['downstream_count'] ?? 0,
            'rawData'              => $this->data,
        ];
    }

    private function enrichRows(array $rows): array
    {
        return array_map(function (array $row) {
            $row['why_included_formatted'] = ReasonCopyFormatter::format($row['why_included'] ?? null, $row['kind'] ?? null);
            $row['kind_label']             = HumanLabelResolver::formatKind($row['kind'] ?? 'unknown');
            $row['risk_formatted']         = RiskCopyFormatter::label($row['risk'] ?? null);
            return $row;
        }, $rows);
    }

    private function getRiskBadgeClass(string $riskBucket): string
    {
        return match ($riskBucket) {
            'critical' => 'risk-critical',
            'high'     => 'risk-high',
            'medium'   => 'risk-medium',
            'low'      => 'risk-low',
            'healthy'  => 'risk-healthy',
            default    => 'risk-unknown',
        };
    }
}
