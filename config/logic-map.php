<?php

return [
    /*
     * Which directories should be scanned by default?
     */
    'scan_paths' => [
        base_path('app'),
        base_path('routes'),
        base_path('packages/dndark'),
    ],

    /*
     * Fully Qualified Namespaces or Classes to ignore
     */
    'ignore_namespaces' => [
        //
    ],

    /*
     * The cache key used to store the snapshot
     */
    'cache_key' => 'logic_map.snapshot',

    /*
     * The cache key used to store the fingerprint
     */
    'fingerprint_key' => 'logic_map.fingerprint',

    /*
     * Cache key prefix for analysis reports (stored separately from graph)
     */
    'analysis_cache_key' => 'logic_map.analysis',

    /*
     * How long should the snapshot be cached? (in seconds)
     */
    'cache_ttl' => 24 * 60 * 60,

    /*
     * Node limits for projections
     */
    'overview_node_limit' => 100,
    'subgraph_node_limit' => 50,

    /*
     * Diff payload limits (graph-to-graph comparison)
     */
    'diff' => [
        'max_node_changes' => 200,
        'max_edge_changes' => 300,
    ],

    /*
     * Default filters for projections
     */
    'filters' => [
        'min_confidence' => 'low',
        'excluded_kinds' => [],
    ],

    'analysis' => [
        'enabled' => true,

        /*
         * Violation thresholds
         */
        'thresholds' => [
            'fat_controller_fan_out' => 10,
            'high_instability' => 0.9,
            'high_coupling' => 20,
        ],

        /*
         * Display labels for analyzers
         */
        'labels' => [
            'circular_dependency' => 'Circular Dependency',
            'fat_controller' => 'Fat Controller',
            'orphan' => 'Orphan Node',
            'dead_code' => 'Dead Code',
            'high_instability' => 'High Instability',
            'high_coupling' => 'High Coupling',
        ],

        /*
         * Descriptions for analyzers (shown in UI)
         */
        'descriptions' => [
            'circular_dependency' => 'Recursive dependency chain found (A → B → A). Fix by extracting shared logic to a lower-level service or interface.',
            'fat_controller' => 'Controller exceeds dependency threshold. Refactor by delegating business logic to Services or Actions.',
            'orphan' => 'Module is not called by or connected to any other parts. May be dead code or incomplete integration.',
            'dead_code' => 'Node is unreachable from configured route entrypoints (depth = null). Candidate for cleanup or wiring.',
            'high_instability' => 'Fragile component that depends on many changing parts but is not depended upon by others.',
            'high_coupling' => 'Tightly coupled module with high connectivity. Hard to test and isolate.',
        ],

        /*
         * Descriptions for severity levels (shown in Scoring Guide)
         */
        'severity_descriptions' => [
            'critical' => 'Circular deps, breaking issues',
            'high' => 'Fat controllers, structural debt',
            'medium' => 'High instability / coupling',
            'low' => 'Orphan / dead-code signals, minor issues',
        ],

        /*
         * Enable/disable individual analyzers
         */
        'analyzers' => [
            'fat_controller' => true,
            'circular_dependency' => true,
            'orphan' => true,
            'dead_code' => true,
            'high_instability' => false,
            'high_coupling' => false,
        ],

        /*
         * OrphanAnalyzer scoping (ADR-014)
         * Only these kinds will be considered for orphan detection.
         * Framework-resolved kinds (command, event, listener, etc.) are excluded.
         */
        'orphan' => [
            'eligible_kinds' => ['controller', 'service', 'model'],
            'ignore_node_ids' => [],
        ],

        /*
         * DeadCodeAnalyzer scoping (ADR-015)
         * Flags nodes with depth = null (unreachable from route entrypoints).
         */
        'dead_code' => [
            'eligible_kinds' => ['controller', 'service', 'repository', 'model', 'job', 'component'],
            'ignore_node_ids' => [],
        ],

        /*
         * Depth calculation settings (ADR-012)
         * depth: ?int — null if unreachable from any entrypoint
         */
        'depth' => [
            'entrypoint_kinds' => ['route'],
            'traversal_edge_types' => ['handles', 'calls', 'dispatches', 'queries', 'resolves'],
        ],

        /*
         * Scoring weights for health score calculation
         */
        'weights' => [
            'critical' => 10,
            'high' => 5,
            'medium' => 2,
            'low' => 1,
        ],

        /*
         * Health grade scales (min_score => grade)
         */
        'grade_scales' => [
            100 => 'S',
            90 => 'A',
            80 => 'B',
            70 => 'C',
            60 => 'D',
            0  => 'F',
        ],

        /*
         * Labels for node kinds
         */
        'kind_labels' => [
            'route' => 'Routes',
            'controller' => 'Controllers',
            'service' => 'Services',
            'repository' => 'Repositories',
            'model' => 'Models',
            'event' => 'Events',
            'job' => 'Jobs',
            'listener' => 'Listeners',
            'command' => 'Commands',
            'component' => 'Components',
            'unknown' => 'Other',
        ],

        /*
         * Colors for UI elements
         */
        'colors' => [
            'grades' => [
                'S' => '#16a34a',
                'A' => '#22c55e',
                'B' => '#84cc16',
                'C' => '#eab308',
                'D' => '#f97316',
                'F' => '#ef4444',
            ],
            'severities' => [
                'critical' => ['bg' => 'rgba(239,68,68,.12)', 'bd' => '#ef4444', 'tx' => '#ef4444'],
                'high' => ['bg' => 'rgba(249,115,22,.1)', 'bd' => '#f97316', 'tx' => '#f97316'],
                'medium' => ['bg' => 'rgba(234,179,8,.1)', 'bd' => '#eab308', 'tx' => '#ca8a04'],
                'low' => ['bg' => 'rgba(34,197,94,.1)', 'bd' => '#22c55e', 'tx' => '#16a34a'],
            ],
        ],

        /*
         * Performance thresholds for UI warnings
         */
        'ui_thresholds' => [
            'large_graph' => 150,
            'hub_utility_fan_in' => 5,
        ],
    ],

    /*
     * Test coverage correlation (Clover XML)
     */
    'coverage' => [
        'enabled' => env('LOGIC_MAP_COVERAGE_ENABLED', true),
        'clover_path' => env('LOGIC_MAP_COVERAGE_CLOVER_PATH', base_path('coverage/clover.xml')),
        'assume_uncovered_when_missing' => false,
        'low_threshold' => 0.5,
        'high_threshold' => 0.8,
        'correlation_kinds' => ['controller', 'service', 'repository', 'model', 'job', 'component'],
        'correlation_risk_levels' => ['critical', 'high'],
    ],

    /*
     * Query-time snapshot resolution policy
     */
    'query' => [
        'resolver' => [
            'strict_resolution' => false,
            'fallback_on_missing_pointer' => true,
            'fallback_on_corrupted_pointer' => true,
        ],
    ],

    /*
     * Export Settings
     */
    'export' => [
        'csv_delimiter' => ',',
        'include_metrics' => true,
    ],

    /*
     * Change Intelligence: Reports, Markdown artifacts, Browser Save
     */
    'change_intelligence' => [
        'viewer_preview_enabled'  => true,
        'report_pages_enabled'    => true,
        'markdown' => [
            'enabled'               => true,
            'save_to_project_docs'  => env('LOGIC_MAP_SAVE_TO_DOCS', false),
            'base_path'             => base_path('docs/logic-map'),
            'include_json_appendix' => true,
        ],
    ],

    /*
     * Documentation Export Settings (logic-map:export-docs)
     */
    'doc_export' => [
        /*
         * Minimum number of segments a workflow must have to generate a dossier.
         * Filters out trivial 0-1 step routes (CRUD read-only, etc.)
         */
        'workflow_min_segments' => 2,

        /*
         * Maximum number of workflow dossiers to export.
         * Workflows are sorted by risk desc → segments desc → slug asc before capping.
         */
        'max_workflows' => 50,

        /*
         * If node_count exceeds this threshold, the node catalog is written to nodes.md
         * instead of being inlined into llms.txt.
         */
        'inline_catalog_max_nodes' => 200,
    ],
];
