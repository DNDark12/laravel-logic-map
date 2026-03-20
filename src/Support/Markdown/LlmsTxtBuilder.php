<?php

namespace dndark\LogicMap\Support\Markdown;

use dndark\LogicMap\Domain\Enums\NodeKind;

/**
 * Builds llms.txt — AI-readable bootstrap context.
 *
 * Sections:
 *   [R] Header metadata
 *   [R] Vocabulary
 *   [R] Node Kinds
 *   [R] Edge Types
 *   [R] ID Format
 *   [R] Workflow Index
 *   [R] Top Risk Entities
 *   [O] Node Summary (only if node_count <= threshold)
 */
class LlmsTxtBuilder
{
    public static function build(
        array  $graphNodes,      // all graph nodes from $graph->getNodes()
        array  $graphEdges,      // all edges, used for edge type discovery
        array  $workflows,       // already filtered/sorted workflow list
        array  $topRiskEntities, // [ { node_id, name, blast_radius, risk } ] sorted desc
        string $fingerprint,
        int    $inlineCatalogMaxNodes = 200
    ): string {
        $lines = [];
        $nodeCount = count($graphNodes);
        $edgeCount = count($graphEdges);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        // ── Header ────────────────────────────────────────────────────
        $lines[] = '# Logic Map Context';
        $lines[] = '> schema_version: 1';
        $lines[] = "> snapshot: {$fingerprint}";
        $lines[] = "> generated: {$generatedAt}";
        $lines[] = "> nodes: {$nodeCount} | edges: {$edgeCount}";
        $lines[] = '';

        // ── Vocabulary ────────────────────────────────────────────────
        $lines[] = '# Vocabulary';
        $lines[] = '- node: a discovered code element (class, method, route, job, event, etc.)';
        $lines[] = '- edge: a directed relationship between two nodes';
        $lines[] = '- snapshot: a frozen point-in-time representation of the dependency graph';
        $lines[] = '- blast_radius: count of nodes transitively affected when a node changes';
        $lines[] = '- workflow: the ordered execution path from an entrypoint through the system';
        $lines[] = '';

        // ── Node Kinds ────────────────────────────────────────────────
        $lines[] = '# Node Kinds';
        $kindDescriptions = [
            'route'      => 'HTTP endpoint (e.g. GET /api/products)',
            'controller' => 'Laravel controller class or action method',
            'service'    => 'Business logic service class or method',
            'repository' => 'Data access layer (DB queries)',
            'model'      => 'Eloquent model',
            'job'        => 'Queued job — marks an async boundary',
            'event'      => 'Domain event',
            'listener'   => 'Event listener (handles a domain event)',
            'command'    => 'Artisan console command',
            'component'  => 'Blade/Livewire component',
        ];

        foreach (NodeKind::cases() as $case) {
            $desc = $kindDescriptions[$case->value] ?? ucfirst($case->value);
            $lines[] = "- {$case->value}: {$desc}";
        }
        $lines[] = '';

        // ── Edge Types ────────────────────────────────────────────────
        $lines[] = '# Edge Types';
        $lines[] = '- calls: synchronous method invocation';
        $lines[] = '- dispatches: async job dispatch (crosses queue boundary)';
        $lines[] = '- fires: domain event emission';
        $lines[] = '- listens: event listener binding (X handles event Y)';
        $lines[] = '- queries: database or repository access';
        $lines[] = '- resolves: IoC container resolution';
        $lines[] = '- handles: console/job handler binding';
        $lines[] = '';

        // ── ID Format ─────────────────────────────────────────────────
        $lines[] = '# ID Format';
        $lines[] = '- Routes: `route:{METHOD} {uri}` — e.g. `route:POST /checkout`';
        $lines[] = '- Methods: `method:{FQCN}@{method}` — e.g. `method:App\Services\OrderService@create`';
        $lines[] = '- Classes: `class:{FQCN}` — e.g. `class:App\Models\Order`';
        $lines[] = '';

        // ── Workflow Index ────────────────────────────────────────────
        $lines[] = '# Workflow Index';
        if (empty($workflows)) {
            $lines[] = '(none exported)';
        } else {
            foreach ($workflows as $wf) {
                $entry  = $wf['entry'] ?? '?';
                $steps  = $wf['segments'] ?? '?';
                $risk   = strtolower($wf['risk'] ?? 'unknown');
                $slug   = $wf['slug'];
                $lines[] = "- {$slug}: {$entry} → {$steps} steps, {$risk} risk";
            }
        }
        $lines[] = '';

        // ── Top Risk Entities ─────────────────────────────────────────
        $lines[] = '# Top Risk Entities';
        // Sort by blast_radius desc, id asc as tiebreaker
        usort($topRiskEntities, function ($a, $b) {
            $diff = ($b['blast_radius'] ?? 0) <=> ($a['blast_radius'] ?? 0);
            return $diff !== 0 ? $diff : strcmp($a['node_id'] ?? '', $b['node_id'] ?? '');
        });
        $topRisks = array_slice($topRiskEntities, 0, 10);

        if (empty($topRisks)) {
            $lines[] = '(none identified)';
        } else {
            foreach ($topRisks as $entity) {
                $id     = $entity['node_id'] ?? '?';
                $radius = $entity['blast_radius'] ?? '?';
                $risk   = strtolower($entity['risk'] ?? 'unknown');
                $lines[] = "- {$id} — blast_radius: {$radius}, risk: {$risk}";
            }
        }
        $lines[] = '';

        // ── Node Summary (optional) ───────────────────────────────────
        if ($nodeCount > $inlineCatalogMaxNodes) {
            $lines[] = '# Node Catalog';
            $lines[] = "(See nodes.md for full catalog — {$nodeCount} nodes exceeds inline threshold of {$inlineCatalogMaxNodes})";
        } else {
            $lines[] = '# Node Summary';
            // Group by kind, sort by kind asc then id asc
            $byKind = [];
            foreach ($graphNodes as $node) {
                $k = $node->kind->value ?? 'unknown';
                $byKind[$k][] = $node;
            }
            ksort($byKind);

            foreach ($byKind as $kind => $nodes) {
                usort($nodes, fn($a, $b) => strcmp($a->id, $b->id));
                $lines[] = "## " . ucfirst($kind) . 's';
                foreach ($nodes as $n) {
                    $name   = $n->metadata['shortLabel'] ?? $n->name ?? $n->id;
                    $module = $n->metadata['module'] ?? '—';
                    $lines[] = "- {$n->id} | {$name} | {$module}";
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
