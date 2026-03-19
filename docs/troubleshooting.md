# Troubleshooting

## Resolver states

Every snapshot-backed JSON response exposes `data._resolution`.

### `resolved_via`

- `requested_snapshot`: response used the explicit `?snapshot=` value
- `active_pointer`: response used the configured active fingerprint
- `latest_snapshot_fallback`: active pointer could not be used, so resolver fell back to the latest valid snapshot

### `pointer_state`

- `ok`: active pointer existed and resolved normally
- `missing`: no active pointer existed
- `corrupted`: active pointer referenced a missing snapshot
- `bypassed`: explicit `?snapshot=` skipped pointer lookup

### `analysis_state`

- `available`: analysis report resolved successfully
- `missing`: graph exists but no matching analysis report was found
- `not_requested`: endpoint only needed the graph snapshot
- `unresolved`: no snapshot could be resolved at all

## Common recovery paths

### No snapshot found

Symptoms:

- `message` says to run `logic-map:build`
- `_resolution.pointer_state` is `missing`

Actions:

```bash
php artisan logic-map:build --force
```

If you expect historical snapshots, make sure `cache_ttl` is long enough to retain them.

### Active pointer is corrupted

Symptoms:

- `_resolution.pointer_state` is `corrupted`
- logs contain a resolver warning

Actions:

```bash
php artisan logic-map:clear-cache
php artisan logic-map:build --force
```

This clears stale pointers and rebuilds both graph + analysis.

### Graph exists but analysis is unavailable

Symptoms:

- endpoint returns `analysis_unavailable`
- `_resolution.analysis_state` is `missing`

Actions:

1. Rebuild the logic map.
2. Verify your analyzer configuration if you recently changed `config/logic-map.php`.
3. Check logs for a warning about a missing analysis report.

### Need deterministic failures in CI

If you want CI to fail instead of silently falling back to the latest snapshot:

```php
'query' => [
    'resolver' => [
        'strict_resolution' => true,
    ],
],
```

Recommended use cases:

- package contract tests
- pipeline validation after cache resets
- debugging active pointer lifecycle regressions

## Snapshot semantics

- `latest_fingerprint`: newest stored graph snapshot
- `active_fingerprint`: last successful full build (graph + analysis)
- `current_fingerprint`: public alias of `active_fingerprint`
- per item `is_current == is_active`

This means the UI and API both treat the active pointer as the current snapshot.

## Useful commands

```bash
php artisan logic-map:build --force
php artisan logic-map:clear-cache
php artisan route:list --path=logic-map
```

If you maintain the package locally inside a host application, ensure the package source paths are included in `scan_paths` when you want package changes to create new snapshots.
