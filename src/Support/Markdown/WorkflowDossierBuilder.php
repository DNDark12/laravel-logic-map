<?php

namespace dndark\LogicMap\Support\Markdown;

use dndark\LogicMap\Support\HumanLabelResolver;

/**
 * Builds workflows/{slug}.md — per-entrypoint workflow dossier.
 * Input format: same $traceResult->data shape used by TraceMarkdownBuilder.
 */
class WorkflowDossierBuilder
{
    public static function build(array $report, string $fingerprint): string
    {
        $nodeId      = $report['target']['node_id'] ?? '?';
        $kindRaw     = $report['target']['kind'] ?? 'unknown';
        $humanName   = $report['target']['name'] ?? $nodeId;
        $kind        = HumanLabelResolver::formatKind($kindRaw);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $summary     = $report['summary'] ?? [];

        $segmentCount   = $summary['segment_count'] ?? 0;
        $branchCount    = $summary['branch_count'] ?? 0;
        $asyncHops      = $summary['async_hops'] ?? 0;
        $truncated      = (bool) ($summary['truncated'] ?? false);
        $maxDepth       = $summary['max_depth'] ?? '?';
        $direction      = $summary['direction'] ?? 'forward';

        $persistenceCount = count($report['persistence_touchpoints'] ?? []);

        $lines = [];

        // ── Frontmatter ───────────────────────────────────────────────
        $lines[] = '---';
        $lines[] = 'artifact_type: "workflow_dossier"';
        $lines[] = 'schema_version: 1';
        $lines[] = 'entry: "' . addslashes($nodeId) . '"';
        $lines[] = 'kind: "' . $kindRaw . '"';
        $lines[] = 'snapshot: "' . $fingerprint . '"';
        $lines[] = 'generated_at: "' . $generatedAt . '"';
        $lines[] = 'segments: ' . $segmentCount;
        $lines[] = 'branches: ' . $branchCount;
        $lines[] = 'async_boundaries: ' . $asyncHops;
        $lines[] = 'persistence_points: ' . $persistenceCount;
        $lines[] = '---';
        $lines[] = '';

        // ── Title ─────────────────────────────────────────────────────
        $lines[] = "# Workflow: {$humanName}";
        $lines[] = '';

        if ($truncated) {
            $lines[] = '> ⚠️ **Trace truncated** — reached depth boundary of ' . $maxDepth . '. Increase `max_depth` for a deeper view.';
            $lines[] = '';
        }

        // ── Summary ───────────────────────────────────────────────────
        $lines[] = '## Summary';
        if (!empty($report['human_summary'])) {
            $lines[] = $report['human_summary'];
            $lines[] = '';
        }
        $lines[] = "**Steps**: {$segmentCount}  |  **Branches**: {$branchCount}  |  **Async**: {$asyncHops}  |  **Persistence**: {$persistenceCount}";
        $lines[] = "**Direction**: {$direction}  |  **Entry**: [{$kind}] `{$nodeId}`";
        $lines[] = '';

        // ── Main Flow ─────────────────────────────────────────────────
        $lines[] = '## Main Flow';
        $segments = $report['segments'] ?? [];
        if (empty($segments)) {
            $lines[] = '_No workflow segments traced from this entry point._';
        } else {
            foreach ($segments as $i => $seg) {
                $step      = $i + 1;
                $from      = $seg['from_node_id'] ?? '?';
                $to        = $seg['to_node_id'] ?? '?';
                $fromLabel = $seg['from_label'] ?? $from;
                $toLabel   = $seg['to_label'] ?? $to;
                $edgeType  = $seg['segment_type'] ?? 'calls';

                $suffix = '';
                if (str_contains($edgeType, 'dispatch')) {
                    $suffix = ' ⚡';
                } elseif (str_contains($edgeType, 'quer') || str_contains($edgeType, 'persist')) {
                    $suffix = ' 💾';
                }

                $lines[] = "{$step}. `{$fromLabel}` → `{$toLabel}` ({$edgeType}){$suffix}";
            }
        }
        $lines[] = '';

        // ── Async Boundaries ──────────────────────────────────────────
        $lines[] = '## Async Boundaries';
        $asyncNodes = array_filter(
            $segments,
            fn($seg) => str_contains($seg['segment_type'] ?? '', 'dispatch') || str_contains($seg['segment_type'] ?? '', 'fires')
        );
        if (empty($asyncNodes)) {
            $lines[] = '_None detected — workflow is fully synchronous._';
        } else {
            foreach ($asyncNodes as $i => $seg) {
                $step  = $i + 1;
                $from  = $seg['from_label'] ?? $seg['from_node_id'] ?? '?';
                $to    = $seg['to_label'] ?? $seg['to_node_id'] ?? '?';
                $type  = $seg['segment_type'] ?? 'dispatches';
                $lines[] = "- Step {$step}: `{$from}` ⇢ `{$to}` ({$type})";
            }
        }
        $lines[] = '';

        // ── Persistence Touchpoints ───────────────────────────────────
        $lines[] = '## Persistence Touchpoints';
        $persistNodes = $report['persistence_touchpoints'] ?? [];
        if (empty($persistNodes)) {
            $lines[] = '_None detected._';
        } else {
            foreach ($persistNodes as $tp) {
                $name = $tp['name'] ?? $tp['node_id'] ?? '?';
                $id   = $tp['node_id'] ?? '';
                $lines[] = "- `{$name}` (`{$id}`)";
            }
        }
        $lines[] = '';

        // ── Decision Points ───────────────────────────────────────────
        $lines[] = '## Decision Points';
        $branchPoints = $report['branch_points'] ?? [];
        if (empty($branchPoints)) {
            $lines[] = '_No branching — linear flow._';
        } else {
            foreach ($branchPoints as $bp) {
                $name    = $bp['name'] ?? $bp['node_id'] ?? '?';
                $count   = $bp['outgoing_count'] ?? '?';
                $branches = $bp['branches'] ?? [];
                $branchList = empty($branches) ? '' : ': ' . implode(', ', $branches);
                $lines[] = "- `{$name}` — {$count} branches{$branchList}";
            }
        }
        $lines[] = '';

        // ── Risk Notes ────────────────────────────────────────────────
        $lines[] = '## Risk Notes';
        $mustReview = $report['review_scope']['must_review'] ?? [];
        if (empty($mustReview)) {
            $lines[] = '_No high-risk nodes identified in this workflow._';
        } else {
            foreach ($mustReview as $row) {
                $name   = $row['name'] ?? '?';
                $id     = $row['node_id'] ?? '';
                $risk   = ucfirst($row['risk'] ?? '?');
                $reason = $row['why_included'] ?? '';
                $lines[] = "- **{$name}** (`{$id}`) — {$risk} risk. {$reason}";
            }
        }

        return implode("\n", $lines);
    }
}
