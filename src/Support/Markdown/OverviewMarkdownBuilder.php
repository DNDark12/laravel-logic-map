<?php

namespace dndark\LogicMap\Support\Markdown;

use dndark\LogicMap\Support\HumanLabelResolver;

/**
 * Builds overview.md — human-readable system narrative for PM + Dev.
 */
class OverviewMarkdownBuilder
{
    /**
     * @param array  $graphMeta   { node_count, edge_count }
     * @param array  $analysis    { health_score, violations, hotspots, violations_breakdown }
     * @param array  $entrypoints [ { node_id, kind, name, segment_count, risk } ]
     * @param array  $workflows   [ { slug, title, segments, async, risk } ] — already filtered/sorted
     * @param string $fingerprint snapshot fingerprint
     */
    public static function build(
        array  $graphMeta,
        array  $analysis,
        array  $entrypoints,
        array  $workflows,
        string $fingerprint
    ): string {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $lines = [];

        // ── Header ────────────────────────────────────────────────────
        $lines[] = '# Application Logic Map';
        $lines[] = '';
        $lines[] = "> Snapshot: `{$fingerprint}` | Generated: {$generatedAt}";
        $lines[] = '';

        // ── System Summary ────────────────────────────────────────────
        $lines[] = '## System Summary';
        $nodeCount  = $graphMeta['node_count'] ?? 0;
        $edgeCount  = $graphMeta['edge_count'] ?? 0;
        $healthScore = $analysis['health_score'] ?? 100;
        $violations  = $analysis['violations'] ?? 0;

        $lines[] = "- **Nodes**: {$nodeCount}  |  **Edges**: {$edgeCount}";
        $lines[] = "- **Health Score**: {$healthScore}/100";
        $lines[] = "- **Violations**: {$violations}";
        $lines[] = '';

        // ── Top Risks ─────────────────────────────────────────────────
        $lines[] = '## Top Risks';
        $hotspots = $analysis['hotspots'] ?? [];
        // Sort by blast_radius desc, take top 5
        usort($hotspots, fn($a, $b) => ($b['blast_radius'] ?? 0) <=> ($a['blast_radius'] ?? 0));
        $topRisks = array_slice($hotspots, 0, 5);

        if (empty($topRisks)) {
            $lines[] = '_No high-risk components identified._';
        } else {
            $icons = ['🔴', '🟠', '🟡', '⚪', '⚪'];
            foreach ($topRisks as $i => $hs) {
                $icon   = $icons[$i] ?? '⚪';
                $name   = $hs['name'] ?? $hs['node_id'] ?? 'Unknown';
                $radius = $hs['blast_radius'] ?? '?';
                $risk   = HumanLabelResolver::formatRisk($hs['risk'] ?? null);
                $lines[] = "{$i}. {$icon} **{$name}** — blast radius {$radius} ({$risk})";
            }
        }
        $lines[] = '';

        // ── Top Hotspots table ────────────────────────────────────────
        $lines[] = '## Top Hotspots';
        // Sort by score desc, take top 10
        usort($hotspots, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $topHotspots = array_slice($hotspots, 0, 10);

        if (empty($topHotspots)) {
            $lines[] = '_No hotspot data available._';
        } else {
            $lines[] = '| Rank | Component | Score | Module |';
            $lines[] = '|------|-----------|-------|--------|';
            foreach ($topHotspots as $rank => $hs) {
                $name   = $hs['name'] ?? $hs['node_id'] ?? 'Unknown';
                $score  = number_format($hs['score'] ?? 0, 1);
                $module = $hs['module'] ?? '—';
                $lines[] = '| ' . ($rank + 1) . " | {$name} | {$score} | {$module} |";
            }
        }
        $lines[] = '';

        // ── Entry Points table ────────────────────────────────────────
        $count = count($entrypoints);
        $lines[] = "## Entry Points ({$count})";

        if (empty($entrypoints)) {
            $lines[] = '_No entrypoints found._';
        } else {
            // Sort: risk desc (critical→high→medium→low→none), then name asc
            usort($entrypoints, function ($a, $b) {
                $riskOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4, '' => 5];
                $ra = $riskOrder[strtolower($a['risk'] ?? '')] ?? 5;
                $rb = $riskOrder[strtolower($b['risk'] ?? '')] ?? 5;
                return $ra !== $rb ? $ra <=> $rb : strcmp($a['name'] ?? '', $b['name'] ?? '');
            });

            $lines[] = '| Type | Name | Steps | Risk |';
            $lines[] = '|------|------|-------|------|';
            foreach ($entrypoints as $ep) {
                $type  = HumanLabelResolver::formatKind($ep['kind'] ?? 'unknown');
                $name  = $ep['name'] ?? $ep['node_id'] ?? '—';
                $steps = $ep['segment_count'] ?? '?';
                $risk  = ucfirst($ep['risk'] ?? 'unknown');
                $lines[] = "| {$type} | {$name} | {$steps} | {$risk} |";
            }
        }
        $lines[] = '';

        // ── Workflow Index ────────────────────────────────────────────
        $lines[] = '## Workflow Index';
        if (empty($workflows)) {
            $lines[] = '_No workflow dossiers were exported._';
        } else {
            foreach ($workflows as $wf) {
                $slug   = $wf['slug'];
                $title  = $wf['title'];
                $steps  = $wf['segments'] ?? '?';
                $async  = $wf['async'] ?? 0;
                $risk   = ucfirst($wf['risk'] ?? 'unknown');
                $lines[] = "- [{$title}](workflows/{$slug}.md) — {$steps} steps, {$async} async, {$risk} risk";
            }
        }
        $lines[] = '';

        // ── How to Use These Docs ─────────────────────────────────────
        $lines[] = '## How to Use These Docs';
        $lines[] = '';
        $lines[] = '- **`overview.md`** (this file) — Start here. High-level health and navigation.';
        $lines[] = '- **`llms.txt`** — Feed this to your AI assistant for project context and vocabulary.';
        $lines[] = '- **`workflows/`** — Drill into specific entry points to understand execution flows.';
        $lines[] = '- **`nodes.md`** *(if present)* — Full node catalog for targeted lookup.';
        $lines[] = '- **`notes/`** — Per-node impact/trace artifacts exported from the Logic Map UI.';
        $lines[] = '';
        $lines[] = '> Generated by [Laravel Logic Map](https://github.com/DNDark12/laravel-logic-map).';

        return implode("\n", $lines);
    }
}
