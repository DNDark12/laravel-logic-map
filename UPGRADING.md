# Upgrade to 2.1 (database-backed storage)

Snapshot storage moved from a package-owned SQLite file (`storage/framework/logic-map/index.sqlite`) to `lm_*` tables on the application's own database connection, created by package migrations. Any Laravel-supported driver (MySQL, Postgres, SQLite, ...) now works, which unblocks staging/production deployments. Query endpoints also stopped hydrating the whole graph per request: the snapshot's graph is a lazy `GraphReader` and every service issues bounded, indexed queries, so request memory is proportional to the response — the default 128M `memory_limit` is enough on staging.

## Steps

1. Run the migrations (they load automatically from the package):

   ```bash
   php artisan migrate
   ```

   To customize, publish first: `php artisan vendor:publish --tag=logic-map-migrations`.

2. Rebuild the index (old SQLite-file snapshots are not migrated):

   ```bash
   php artisan logic-map:index --force
   ```

3. Optionally delete the obsolete file store: `storage/framework/logic-map/`.

## Breaking changes

- Config `logic-map.storage.sqlite_path` was removed; use `logic-map.storage.connection` (null = default connection, or set `LOGIC_MAP_DB_CONNECTION`).
- `DNDark\LogicMap\Repositories\Sqlite\*` classes are no longer bound or supported; use `DNDark\LogicMap\Repositories\Database\*`.
- `GraphSnapshot::$graph` is typed `GraphReader` (implemented by both `KnowledgeGraph` and the lazy `DatabaseGraph`).
- `RuntimeEvidenceMerger::merge()` gained an optional `$scopeNodeIds` parameter, and `runtime.relations` in API responses now lists only observed and scope-adjacent relations instead of every static edge.
- `SqliteSchema::VERSION` moved to `DNDark\LogicMap\Support\SchemaVersion::VERSION` (value unchanged, so snapshot ids and fingerprints are stable).

## New: AI documentation bundle (additive, no action required)

`php artisan logic-map:export-ai` exports a machine-readable, impact-weighted bundle (`graph.json`, `impact/*.json`, `modules/*.json`, `llms.txt`, `index.md`) so an AI agent can determine the blast radius and severity of a prospective change without running the application. It is purely additive: no config keys were renamed, and `logic-map:export-docs` (human dossiers) is unaffected.

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

All V1 `/logic-map/*` overview/subgraph/trace/report/export endpoints and cache-backed snapshots are deprecated.

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

The V2 default scan scope includes `tests` so symbol/Git impact can return a selected test scope. If an already-published config predates this default, add `tests` manually and rebuild the snapshot.

PHP namespaces changed from the legacy lowercase prefix to `DNDark\LogicMap`. After updating a path repository, run Composer install/update (or `composer reinstall dndark/laravel-logic-map` for a mirrored Docker install) before dumping autoload so Composer refreshes installed package metadata as well as the class map.

If Artisan fails with `Class "dndark\LogicMap\LogicMapServiceProvider" not found`, Laravel is still reading the generated V1 discovery cache. Remove only the generated discovery manifests, then rebuild them with the new Composer metadata:

```bash
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
composer dump-autoload
```

For a Docker consumer, remove those two files in the mounted project and run the final `composer dump-autoload` inside the PHP container.

## HTTP and runtime defaults

HTTP access is limited to `local` and `testing` by default. Add production only with application-owned authentication/authorization middleware.

Runtime collection is disabled by default. Enabling it is optional and does not change static snapshot certainty. Review retention, sampling, middleware groups, and privacy constraints before use.

## Rollback

Rollback requires restoring the previous package version plus its published config/views/assets. V2's SQLite file is isolated and can be removed with `php artisan logic-map:clear --force` while V2 is installed.
