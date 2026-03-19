# Architecture

Laravel Logic Map has two distinct runtime paths:

- **Build pipeline**: scans source files, parses AST, computes metrics, runs analyzers, persists graph snapshots and analysis reports.
- **Query pipeline**: resolves an existing snapshot pointer and serves projections/exports without rebuilding or reparsing on the request path.

## Build pipeline

`php artisan logic-map:build` performs the only expensive work in the package:

1. `FileDiscovery` enumerates files from `scan_paths`.
2. `Fingerprint` creates a deterministic snapshot key from file metadata.
3. `AstParser` extracts the canonical graph.
4. Structural metrics are attached to graph nodes.
5. `BuildLogicMapService` stores the graph snapshot.
6. `ArchitectureAnalyzer` derives violations, risk, health, and node risk maps.
7. The analysis report is stored under a compound key: `graph fingerprint + analysis config hash`.
8. After graph and analysis are both persisted, the repository updates the **active fingerprint**.

The active fingerprint therefore represents the latest successful **full build**, not just the latest stored graph.

## Query pipeline

All JSON endpoints and exports resolve cached artifacts through `SnapshotResolver`.

Resolution order:

1. If `?snapshot=...` is provided, bypass pointers and resolve that exact snapshot.
2. Otherwise read the configured active fingerprint.
3. If the active pointer is missing or corrupted and fallback is enabled, use the latest valid snapshot.
4. For analysis-backed endpoints, try the exact `analysis_config_hash` first, then the latest compatible report for the same graph fingerprint.

The query pipeline never calls file discovery or fingerprint generation.

## Resolution metadata

Snapshot-backed responses expose `data._resolution`:

- `requested_snapshot`: echoed explicit snapshot parameter, or `null`
- `resolved_via`: `requested_snapshot`, `active_pointer`, or `latest_snapshot_fallback`
- `resolved_fingerprint`: fingerprint actually used for the response
- `pointer_state`: `ok`, `missing`, `corrupted`, or `bypassed`
- `analysis_state`: `available`, `missing`, `not_requested`, or `unresolved`

This makes fallback behavior observable for API consumers and UI debugging.

## Snapshot storage

The cache repository persists four related pieces of state:

- graph snapshots keyed by fingerprint
- snapshot metadata including build-time `generated_at`
- analysis reports keyed by `fingerprint.configHash`
- lifecycle pointers: `latest fingerprint` and `active fingerprint`

`logic-map:clear-cache` removes all four to avoid stale pointer state.

## Export modes

The package exposes four export modes:

- `/logic-map/export/graph` — canonical graph only
- `/logic-map/export/analysis` — derived analysis only
- `/logic-map/export/bundle` — graph + analysis in one payload
- `/logic-map/export/csv` — flat node metrics plus risk projection columns

`/logic-map/export/json` is a deprecated alias of `/logic-map/export/bundle` and includes deprecation headers.

## Strict resolution mode

Set the resolver to strict mode when you want CI or package tests to fail fast instead of silently falling back:

```php
'query' => [
    'resolver' => [
        'strict_resolution' => true,
    ],
],
```

With strict mode enabled:

- missing active pointers return an error
- corrupted pointers return an error
- analysis-backed endpoints do not degrade through fallback when the requested analysis is unavailable
