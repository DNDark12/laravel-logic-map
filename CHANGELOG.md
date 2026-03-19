# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.5] - 2026-03-19

### Changed
- Increased default snapshot cache TTL to `24 * 60 * 60` seconds to retain more historical snapshots during local package testing.
- Refreshed package README release metadata for the `v1.1.5` tag.

### Fixed
- Fixed critical CI failure due to namespace case-sensitivity mismatch (`DNDark` vs `dndark`) in `SubgraphProjector`.

## [1.1.4] - 2026-03-19

### Added
- Query/application layer split with `SnapshotResolver`, `GraphReadService`, `AnalysisReadService`, and `ExportReadService`.
- Typed JSON result model with structured `errors[]` entries and `_resolution` metadata on JSON responses.
- Snapshot-aware export endpoints: `/export/graph`, `/export/analysis`, `/export/bundle`.
- Explicit `active_fingerprint`, `current_fingerprint`, and `is_active` semantics in snapshot responses.
- Package-level Testbench base `TestCase` for package feature tests and CI isolation.

### Changed
- `GET /logic-map/export/json` now aliases the bundle export contract instead of acting as a separate JSON format.
- Query endpoints now resolve snapshots from the active pointer / latest valid cached snapshot without rebuilding or reparsing on the request path.
- Snapshot cache TTL default is now `24 * 60 * 60` seconds.

### Fixed
- Fixed intermittent 404s on subgraph routes by supporting URL-encoded node IDs consistently.
- Corrected health grade color mapping in configuration.
- Restored package test namespace/bootstrap wiring used by CI and isolated package runs.

## [1.1.0] - 2026-03-19

### Added

- Snapshot time-travel endpoint (`GET /logic-map/snapshots`) and topbar selector.
- Snapshot-to-snapshot graph diff endpoint (`GET /logic-map/diff`).
- Complexity heatmap mode (UI toggle + keyboard shortcut `H`).
- Dead code analyzer (`depth === null` from route entrypoints).
- Coverage correlation ingestion and health-panel insights.
- Mobile overflow actions menu for full feature access on narrow screens.

### Changed

- Health grade color mapping refined (`S`, `A`, `B` now use distinct colors).
- JSON export now downloads a `.json` file instead of redirecting to raw JSON.
- Snapshot and layout/hops controls synchronized across desktop and mobile UI.
- Header controls normalized to consistent heights across breakpoints.

### Fixed

- Subgraph route now supports slash-containing IDs (`route:logic-map/...`).
- Subgraph no longer removes isolated seed nodes (prevents blank canvas).
- Initial render always runs layout (prevents node overlap at origin on first load).
- Subgraph panel hides duplicate `Explore SubGraph` action while already in Subgraph mode.
- Subgraph floating controls stay visible on short-height viewports.

## [1.0.0] - 2026-03-16

### Added

- **Graph Engine**: AST-based code analysis with `nikic/php-parser`
- **Domain Model**: Node (10 kinds), Edge (4 types), Graph with helper methods
- **Projectors**: Overview, Subgraph, Search, Meta — projection-first API design
- **7 Structural Metrics**: in_degree, out_degree, fan_in, fan_out, instability, coupling, depth
- **5 Analyzers**: FatController, CircularDependency, Orphan, HighInstability, HighCoupling
- **Risk Scoring**: Derived from violations + metrics with explainable reasons
- **Health Score**: 0-100 score with A-F grade
- **9 API Endpoints**: overview, subgraph, search, meta, violations, health, export/json, export/csv
- **Business Intent Extraction**: Added `IntentExtractor` for semantic mapping (Action, Domain, Result, Trigger)
- **Interactive UI**: Complete overhaul with dark mode, module explorer, and detail panels
- **Legacy UI Parity**: Implemented edge animations, subgraph isolation, and keyboard shortcuts (1-4, F, T, S)
- **Modular UI**: Refactored `graph.blade.php` to use standalone `logic-map.css` and `logic-map.js`
- **Build-time caching**: Fingerprint-based cache with compound analysis key
- **101 tests** with 724 assertions
