<?php

namespace dndark\LogicMap\Support\Markdown;

use dndark\LogicMap\Support\HumanLabelResolver;

class TraceMarkdownBuilder
{
    public static function build(array $report, string $snapshot, bool $includeJson = true): string
    {
        $nodeId = $report['target']['node_id'];
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $lines = [];

        $lines[] = '---';
        $lines[] = 'artifact_type: "trace_dossier"';
        $lines[] = 'artifact_version: "1.4"';
        $lines[] = 'node_id: "' . addslashes($nodeId) . '"';
        $lines[] = 'snapshot: "' . $snapshot . '"';
        $lines[] = 'generated_at: "' . $generatedAt . '"';
        $lines[] = '---';
        $lines[] = '';

        $humanName = $report['target']['name'];
        $kind = HumanLabelResolver::formatKind($report['target']['kind']);
        
        $lines[] = "# Logic Map Trace Dossier: {$humanName}";
        $lines[] = '';
        $lines[] = '## Executive Summary';
        $lines[] = "Target **[{$kind}] {$humanName}** has {$report['summary']['segment_count']} segments with {$report['summary']['branch_count']} branch points.";
        $lines[] = "Trace direction: **{$report['summary']['direction']}**, bounded at depth **{$report['summary']['max_depth']}**" . ($report['summary']['truncated'] ? ' (Truncated from hitting boundary)' : '') . ".";
        
        if (!empty($report['human_summary'])) {
            $lines[] = '';
            $lines[] = $report['human_summary'];
        }
        $lines[] = '';
        
        $lines[] = '## Main Flow';
        if (empty($report['segments'])) {
            $lines[] = '_No workflow segments traced._';
        } else {
            foreach ($report['segments'] as $segment) {
                $lines[] = "- [{$segment['segment_type']}] `{$segment['from_node_id']}` -> `{$segment['to_node_id']}`";
            }
        }
        $lines[] = '';

        $lines[] = '## Entrypoints';
        if (empty($report['entrypoints'])) {
            $lines[] = '_None traced._';
        } else {
            foreach ($report['entrypoints'] as $ep) {
                $lines[] = "- **{$ep['name']}** (`{$ep['node_id']}`)";
            }
        }
        $lines[] = '';

        $lines[] = '## Async Boundaries';
        $lines[] = "Total Async Hops: {$report['summary']['async_hops']}";
        $lines[] = '';

        $lines[] = '## Persistence Touchpoints';
        if (empty($report['persistence_touchpoints'])) {
            $lines[] = '_No persistence nodes (models/repositories) touched._';
        } else {
            foreach ($report['persistence_touchpoints'] as $tp) {
                $lines[] = "- **{$tp['name']}** (`{$tp['node_id']}`)";
            }
        }
        $lines[] = '';

        $lines[] = '## Branch Points';
        if (empty($report['branch_points'])) {
            $lines[] = '_No branching nodes traced._';
        } else {
            foreach ($report['branch_points'] as $bp) {
                $lines[] = "- **{$bp['name']}** branches to {$bp['outgoing_count']} nodes: " . implode(', ', $bp['branches']);
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
