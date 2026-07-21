# Laravel Logic Map 2.0

Laravel Logic Map builds an evidence-backed semantic graph of a Laravel application. It answers workflow and change-impact questions that a basic call graph cannot: which route reaches a service, where branches and transactions occur, which jobs/events/listeners continue the flow, which tables or external systems are affected, and which modules/tests should be reviewed after a change.

## What 2.0 answers

- If function A changes, which callers, workflows, modules, shared resources, external contracts, and tests are affected?
- What is the full flow from a route/command/job through validation, authorization, business logic, branches, transactions, async boundaries, persistence, cache, notifications, and external calls?
- Which relationships are proven by AST/Laravel boot facts, which are probable/possible, and which were observed at runtime?
- Where did analysis stop because a target was dynamic or ambiguous?

The index is deterministic and stored in `lm_*` tables on the application's configured database connection. Runtime collection is optional and never replaces static evidence.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- `ext-pdo` and the PDO driver used by the application's database connection
- Composer

## Installation

```bash
composer require --dev dndark/laravel-logic-map
php artisan vendor:publish --tag=logic-map-config
php artisan vendor:publish --tag=logic-map-assets --force
```

The Composer package name remains lowercase (`dndark/laravel-logic-map`), as required by Composer. PHP namespaces use the case-sensitive PSR-4 prefix `DNDark\LogicMap`.

## Indexing

```bash
php artisan logic-map:index --force
php artisan logic-map:status
```

Configuration is flat under `logic-map.*`; there is no `logic-map.v2.*` wrapper. The defaults scan `app`, `routes`, `database`, `config`, and `tests`, then store the active snapshot in `lm_*` tables on the application's database connection (created by the package migrations — run `php artisan migrate`; override the connection with `logic-map.storage.connection` / `LOGIC_MAP_DB_CONNECTION`). The `tests` root is required for impact reports to select related tests; remove it only when that trade-off is intentional.

```php
// config/logic-map.php
'scan_paths' => ['app', 'routes', 'database', 'config', 'tests'],
'excludes' => ['app/Generated'],
```

Open the protected viewer at `/logic-map` after publishing assets and creating an index.

## Workflow examples

Use a canonical node ID or an exact qualified name:

```bash
php artisan logic-map:workflow 'route:POST:orders/{order}/cancel' --format=json
php artisan logic-map:workflow 'route:POST:orders/{order}/cancel' --format=mermaid
php artisan logic-map:workflow 'App\Services\OrderService::cancel' --format=markdown --output=docs/order-cancel.md
```

Workflow output includes ordered steps, decisions, terminal paths, transaction segments, sync/async boundaries, effects, gaps, truncation metadata, and evidence IDs.

## Impact examples

Analyze one symbol or a Git range:

```bash
php artisan logic-map:impact 'method:App\Services\OrderService::cancel' --format=json
php artisan logic-map:impact --base=origin/main --head=HEAD --format=markdown
```

Impact is reason-grouped rather than a flat dependency list. Each affected symbol contains its ordered node/edge chain and evidence IDs. Categories include hard dependency, workflow, async, shared state, external contract, module, uncertainty, and test scope.

## AI documentation export

Export the active V2 snapshot as module and workflow dossiers:

```bash
php artisan logic-map:export-docs
php artisan logic-map:export-docs --output=docs/architecture-map --force
```

The command writes `overview.md`, `modules/*.md`, and `workflows/*.md`. Module dossiers use the module workflow projection rather than treating every module member as a changed symbol. Workflow dossiers embed Mermaid diagrams together with ordered steps, effects, gaps, and evidence.

Export a machine-readable, impact-weighted bundle for AI agents:

```bash
php artisan logic-map:export-ai
php artisan logic-map:export-ai --symbols='method:App\Services\OrderService::cancel' --force
```

The command writes `graph.json` (full node/edge/module inventory), `impact/<symbol-slug>.json` (per-symbol affected-symbol list with an explainable `score`/`band`/`factors` weight), `modules/<slug>.json` (membership and entrypoints), `llms.txt` (agent preamble and weight legend), and `index.md`. The bundle is a pure function of the active snapshot — byte-identical across repeated exports — so it is safe to commit or serve as a build artifact.

## HTTP API

```text
GET  /logic-map/api/status
GET  /logic-map/api/symbols/search?q=cancel
GET  /logic-map/api/symbols/{encoded-id}/context
GET  /logic-map/api/workflows/{encoded-id}
POST /logic-map/api/impact
GET  /logic-map/api/modules
GET  /logic-map/api/modules/{encoded-id}
```

Canonical IDs in URL path segments use base64url encoding. Complete envelopes and examples are published on the developer docs site.

## Evidence and certainty

Every graph relation references evidence records with an origin (`static_ast`, `laravel_boot`, `runtime`, or `git_diff`), detector, certainty (`certain`, `probable`, or `possible`), source location when available, and bounded attributes. Missing evidence remains unknown; it is never reported as proof that code did not execute.

## HTTP viewer protection

The viewer/API are enabled only in `local` and `testing` by default and run through the configured middleware:

```php
'http' => [
    'enabled' => true,
    'allowed_environments' => ['local', 'testing'],
    'middleware' => ['web'],
],
```

If production access is required, explicitly add the environment and an authentication/authorization middleware owned by the consumer application.

## Runtime evidence (opt-in)

Runtime collection is disabled by default. Enable it only in an environment where bounded observations are appropriate:

```php
'runtime' => [
    'enabled' => true,
    'sample_rate' => 0.1,
    'retention_days' => 7,
    'collect_cache_events' => false,
    'middleware_groups' => ['web', 'api'],
],
```

Only observations with stable source and target node IDs, a compatible relation kind, and the same snapshot ID can overlay static queries. Coverage uses only these phrases: `Observed in N selected runtime sessions`, `Not observed in selected sessions`, and `No runtime data available`.

## Known static-analysis limits

- Highly dynamic container resolution, reflection, runtime-generated routes, dynamic method names, and opaque package internals may remain unresolved.
- Runtime proximity alone is not treated as causality.
- Static non-observation does not prove a path is impossible; runtime non-observation does not prove it never executes.
- Bounded query limits can truncate a graph; always inspect `meta`/`truncation` and frontier data.

## Commands

```text
logic-map:index
logic-map:status
logic-map:workflow
logic-map:impact
logic-map:export-docs
logic-map:export-ai
logic-map:clear
```

V1 cache snapshots, routes, reports, and UI are intentionally removed. The `export-docs` name is reused by a V2-native implementation; no V1 graph/report compatibility layer is loaded. See [UPGRADING.md](UPGRADING.md).

## License

MIT. See [LICENSE](LICENSE).
