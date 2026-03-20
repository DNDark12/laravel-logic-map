<?php

namespace dndark\LogicMap\Support\Markdown;

use dndark\LogicMap\Support\HumanLabelResolver;

class ImpactMarkdownBuilder
{
    public static function build(array $report, string $snapshot, bool $includeJson = true): string
    {
        $nodeId = $report['target']['node_id'];
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $lines = [];

        $lines[] = '---';
        $lines[] = 'artifact_type: "impact_report"';
        $lines[] = 'artifact_version: "1.4"';
        $lines[] = 'node_id: "' . addslashes($nodeId) . '"';
        $lines[] = 'snapshot: "' . $snapshot . '"';
        $lines[] = 'generated_at: "' . $generatedAt . '"';
        $lines[] = '---';
        $lines[] = '';

        $humanName = $report['target']['name'];
        $kind = HumanLabelResolver::formatKind($report['target']['kind']);
        
        $lines[] = "# Logic Map Impact Report: {$humanName}";
        $lines[] = '';
        $lines[] = '## Executive Summary';
        $lines[] = "Target **[{$kind}] {$humanName}** has a blast radius score of **{$report['summary']['blast_radius_score']}** (Risk Bucket: **" . strtoupper($report['summary']['risk_bucket']) . "**).";
        
        if (!empty($report['human_summary'])) {
            $lines[] = '';
            $lines[] = $report['human_summary'];
        }
        $lines[] = '';
        
        $lines[] = '## Affected Flow';
        $lines[] = "- **Upstream Dependencies**: {$report['summary']['upstream_count']}";
        $lines[] = "- **Downstream Capabilities**: {$report['summary']['downstream_count']}";
        $lines[] = '';

        $lines[] = '## Critical Touches';
        if (empty($report['critical_touches'])) {
            $lines[] = '_No critical nodes touched._';
        } else {
            foreach ($report['critical_touches'] as $touch) {
                $reasons = implode(', ', $touch['reasons'] ?? []);
                $lines[] = "- `{$touch['node_id']}` ({$reasons})";
            }
        }
        $lines[] = '';

        $lines[] = '## Must Review';
        if (empty($report['review_scope']['must_review'])) {
            $lines[] = '_None._';
        } else {
            foreach ($report['review_scope']['must_review'] as $row) {
                $lines[] = "- **{$row['name']}** [{$row['kind']}] - Reason: {$row['why_included']}";
            }
        }
        $lines[] = '';

        $lines[] = '## Should Review';
        if (empty($report['review_scope']['should_review'])) {
            $lines[] = '_None._';
        } else {
            foreach ($report['review_scope']['should_review'] as $row) {
                $lines[] = "- **{$row['name']}** [{$row['kind']}] - Reason: {$row['why_included']}";
            }
        }
        $lines[] = '';

        $lines[] = '## Test Focus';
        if (empty($report['review_scope']['test_focus'])) {
            $lines[] = '_No high-risk low-coverage nodes identified._';
        } else {
            foreach ($report['review_scope']['test_focus'] as $row) {
                $lines[] = "- **{$row['name']}** [{$row['kind']}] - Reason: {$row['why_included']}";
            }
        }
        $lines[] = '';

        if ($includeJson) {
            $lines[] = '## Raw JSON';
            $lines[] = '```json';
            $lines[] = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
