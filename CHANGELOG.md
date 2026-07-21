# Changelog

## 2.0.0 - 2026-07-17

### Added

- Deterministic PHP/Laravel semantic index with SQLite snapshots.
- Canonical symbol, module, workflow, effect, evidence, and diagnostic contracts.
- Full workflow projection with branches, terminals, transactions, async boundaries, gaps, and Mermaid/Markdown exports.
- Reason-grouped symbol/Git change impact with shared resources, modules, uncertainty, and test scope.
- Protected local HTTP viewer and bounded JSON API.
- Opt-in sanitized runtime sessions and snapshot-scoped evidence overlay.
- PHP namespace casing standardized as `DNDark\LogicMap`.
- Effective Artisan command names resolve to their unique command class, including signatures with arguments/options.

### Changed

- Configuration is flat under `logic-map.*`.
- Default scan paths include `tests` so impact analysis can select related tests.
- Response-size limiting now trims large result lists with bounded search instead of item-by-item JSON re-encoding.
- Package now requires `ext-pdo` and `ext-pdo_sqlite`.

### Fixed

- PHP 8 first-class callables no longer crash Eloquent/facade fact collection.
- Command workflows no longer fan out to every command class in the application.

### Removed

- All V1 cache graph, analysis, report, command, route, controller, UI, asset, and test surfaces.
