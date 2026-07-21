<?php

return [
    /* Repository-relative paths scanned by the semantic indexer. */
    'scan_paths' => [
        'app',
        'routes',
        'database',
        'config',
        'tests',
    ],

    /* Additional repository-relative paths excluded from indexing. */
    'excludes' => [],

    /* Indexing runtime requirements. */
    'indexing' => [
        /*
         * Minimum memory_limit for logic-map:index. Real apps easily exceed
         * PHP CLI's 128M default (silent exit 255). Only raised, never
         * lowered; '-1' = unlimited, null = leave php.ini untouched.
         */
        'memory_limit' => '1G',
    ],

    /*
     * Snapshot and runtime-evidence store. Data lives in the lm_* tables
     * created by the package migrations, on the application's own database
     * connection, so any Laravel-supported driver works and query-path memory
     * stays proportional to the response rather than the whole snapshot.
     *
     * 'connection' => null uses the default connection; set a name (e.g.
     * 'logic_map') to isolate the tables on a dedicated connection.
     */
    'storage' => [
        'connection' => env('LOGIC_MAP_DB_CONNECTION'),
    ],

    'evidence' => [
        'expression_max_length' => 500,
    ],

    /* The viewer is local/testing-only unless the consumer explicitly widens this list. */
    'http' => [
        'enabled' => true,
        'allowed_environments' => ['local', 'testing'],
        'middleware' => ['web'],

        /* Minimum memory_limit for viewer endpoints (graph hydration). Same
           semantics as indexing.memory_limit: only raised, never lowered. */
        'memory_limit' => '1G',
    ],

    /* Shared traversal and response bounds for HTTP and CLI queries. */
    'query' => [
        'max_depth' => 12,
        'max_nodes' => 500,
        'max_edges' => 1000,
        'max_response_bytes' => 2_000_000,
        'max_search_results' => 50,
    ],

    'export' => [
        'allow_absolute_paths' => false,
    ],

    'doc_export' => [
        'output' => 'docs/logic-map',
        'max_modules' => 100,
        'max_workflows' => 500,

        /*
         * Machine-readable AI bundle (logic-map:export-ai). Weights turn each
         * ImpactReason into an explainable 0..1 score via
         * score = category x confidence x level_decay x runtime_factor.
         * Every value below is safe to override individually; missing keys
         * fall back to these defaults. See docs/ai-bundle.md.
         */
        'ai' => [
            'output' => 'docs/logic-map-ai',
            'max_impact_symbols' => 200,
        ],

        'weights' => [
            /* Strength of the relation kind that connects the changed and affected symbol. */
            'category' => [
                'hard_dependency' => 1.0,
                'workflow' => 0.8,
                'external_contract' => 0.8,
                'async' => 0.7,
                'shared_state' => 0.6,
                'module' => 0.4,
                'test_scope' => 0.4,
                'uncertainty' => 0.3,
            ],
            /* Strongest static evidence Certainty backing the relation. */
            'confidence' => [
                'certain' => 1.0,
                'probable' => 0.6,
                'possible' => 0.3,
            ],
            /* Decay by ImpactLevel / traversal depth ("breaks"/"direct" never decay). */
            'level' => [
                'breaks' => 1.0,
                'direct' => 1.0,
                'shared_resource' => 0.7,
                'possible' => 0.3,
                'transitive_decay_base' => 0.5,
            ],
            /* Whether the relation was also (or only) seen in opt-in runtime traces. */
            'runtime' => [
                'observed' => 1.0,
                'static_only' => 0.9,
                'runtime_only' => 0.7,
            ],
            /* Score thresholds mapping to ImpactBand (Critical/High/Medium/Low). */
            'bands' => [
                'critical' => 0.70,
                'high' => 0.45,
                'medium' => 0.20,
            ],
        ],
    ],

    /* Opt-in sanitized observations; disabled by default. */
    'runtime' => [
        'enabled' => false,
        'sample_rate' => 1.0,
        'retention_days' => 7,
        'max_sessions' => 1000,
        'max_observations_per_session' => 5000,
        'collect_cache_events' => false,
        'middleware_groups' => ['web', 'api'],
    ],

    'modules' => [
        'explicit' => [],
        'namespace_roots' => ['App\\' => 1],
        'directory_roots' => ['app/Modules', 'app/Domain'],
        'fallback' => 'Core',
    ],

    'classifier' => [
        'namespace_conventions' => [
            'Services' => 'service',
            'Repositories' => 'repository',
        ],
    ],
];
