<?php

return [
    /*
     * Which directories should be scanned by default?
     */
    'scan_paths' => [
        base_path('app'),
        base_path('routes'),
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
    'cache_ttl' => 3600,

    /*
     * Node limits for projections
     */
    'overview_node_limit' => 100,
    'subgraph_node_limit' => 50,

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
         * Enable/disable individual analyzers
         */
        'analyzers' => [
            'fat_controller' => true,
            'circular_dependency' => true,
            'orphan' => true,
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
            'critical' => 25,
            'high' => 10,
            'medium' => 5,
            'low' => 1,
        ],
    ],

    /*
     * Export Settings
     */
    'export' => [
        'csv_delimiter' => ',',
        'include_metrics' => true,
    ],
];
