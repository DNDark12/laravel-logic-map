# Upgrade to 2.0

Version 2.0 is a deliberate breaking replacement of V1. There is no V1 API, cache, command, route, report, asset, or UI compatibility layer.

## Before upgrading

Back up any customized published V1 views/assets and any generated reports you still need. They are not read or migrated by 2.0.

Confirm PHP has both `ext-pdo` and `ext-pdo_sqlite`:

```bash
php -m | grep -E 'PDO|pdo_sqlite'
```

## Removed

```text
logic-map:build
logic-map:analyze
logic-map:export-docs
logic-map:export-note
logic-map:clear-cache
```

All V1 `/logic-map/*` overview/subgraph/trace/report/export endpoints and cache-backed snapshots are removed.

## New surface

```bash
php artisan vendor:publish --tag=logic-map-config --force
php artisan vendor:publish --tag=logic-map-assets --force
php artisan logic-map:index --force
php artisan logic-map:status
```

The viewer is `/logic-map`; JSON endpoints are under `/logic-map/api`.

## Required rebuild

Old snapshots cannot be upgraded. V2 uses a package-owned SQLite schema and new canonical node/edge/evidence contracts. Run `logic-map:index --force` after installation.

Configuration moved from legacy cache/analysis keys and the transitional `logic-map.v2.*` wrapper to flat keys such as `logic-map.scan_paths`, `logic-map.storage.sqlite_path`, `logic-map.query.*`, and `logic-map.runtime.*`.

## HTTP and runtime defaults

HTTP access is limited to `local` and `testing` by default. Add production only with application-owned authentication/authorization middleware.

Runtime collection is disabled by default. Enabling it is optional and does not change static snapshot certainty. Review retention, sampling, middleware groups, and privacy constraints before use.

## Rollback

Rollback requires restoring the previous package version plus its published config/views/assets. V2's SQLite file is isolated and can be removed with `php artisan logic-map:clear --force` while V2 is installed.
