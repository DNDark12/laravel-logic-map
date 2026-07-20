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
