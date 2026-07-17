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

    /* Package-owned SQLite snapshot and runtime-evidence store. */
    'storage' => [
        'sqlite_path' => 'storage/framework/logic-map/index.sqlite',
    ],

    'evidence' => [
        'expression_max_length' => 500,
    ],

    /* The viewer is local/testing-only unless the consumer explicitly widens this list. */
    'http' => [
        'enabled' => true,
        'allowed_environments' => ['local', 'testing'],
        'middleware' => ['web'],
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
