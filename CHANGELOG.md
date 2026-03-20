# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.3.0] - 2026-03-20

### Added
- **Change Intelligence — Impact Analysis**: `GET /logic-map/impact/{id}` endpoint surfaces blast-radius scoring (0–100), upstream/downstream traversal, `critical_touches` (persistence, async boundary, high-risk nodes), and a `review_scope` (must_review / should_review / test_focus) for a given node.
- **Change Intelligence — Workflow Trace**: `GET /logic-map/trace/{id}` endpoint traces workflow segments for a node in `forward` or `backward` direction, surfacing async segment boundaries, `branch_points`, `entrypoints` (route nodes for backward traces), and `persistence_touchpoints`.
- **Shared BFS Traversal Engine**: `GraphWalker` and `TraversalPolicy` — deterministic, cycle-safe BFS traversal shared by both Impact and Trace endpoints.
- `ChangeImpactReport` and `WorkflowTraceReport` — dedicated query-time DTOs; these artifacts are never embedded into canonical graph payloads.
- Blast-radius score formula: `min(100, 2*downstream + 1*upstream + 6*persistence + 5*async_boundary + 4*high_risk + 3*cross_module + 4*high_risk_low_coverage)`.
- All error responses include structured `errors[0].type` for typed error handling by clients.
- 17 new automated tests covering structure, validation (422), 404 scenarios, blast-radius scoring, async segment detection, and directional traversal.



### Fixed
- Resolved PHPUnit CI warning "No tests found in AstParserTest" by standardizing on `#[Test]` attributes.

## [1.2.0] - 2026-03-19

### Added
- `GET /logic-map/hotspots` with `kind`, `module`, `risk`, and `limit` filters plus deterministic hotspot sorting.
- `HealthPayloadBuilder` and `HotspotsBuilder` to keep `AnalysisReadService` orchestration-only.
- Package docs for build/query architecture and resolver troubleshooting.
- Browser smoke test docs and a Playwright regression script for the detail-panel `hide -> restore -> clear` flow across desktop, tablet, and mobile breakpoints.

### Changed
- Deprecated `GET /logic-map/export/json` in favor of `/logic-map/export/bundle`; alias responses now emit migration headers.
- Snapshot resolution metadata now includes `requested_snapshot`, `resolved_via`, `resolved_fingerprint`, `pointer_state`, and `analysis_state`.
- `graph.metadata.generated_at` is now sourced from build-time snapshot metadata instead of query-time timestamps.
- Active snapshot lifecycle is explicit: `putSnapshot()` only updates the latest fingerprint, while successful builds call `setActiveFingerprint()` after graph + analysis persistence.
- Added `logic-map.query.resolver.strict_resolution` to disable fallback behavior in CI/test scenarios.
- Viewer export controls now expose explicit Graph JSON, Analysis JSON, Bundle JSON, and CSV actions across desktop and mobile layouts.
- Viewer themes now read semantic CSS token variables for canvas, node, edge, and risk rendering.
- Viewer detail panel now supports `expanded`, `peek`, and `hidden` states with restore controls on both desktop and mobile layouts.
- Package PHPUnit tests now use `#[Test]` attributes instead of deprecated doc-comment metadata.

### Fixed
- Resolver now logs warnings for missing/corrupted active pointers and for graph snapshots that have no matching analysis report.
- `logic-map:clear-cache` removes snapshots, analysis payloads, latest fingerprints, and active pointers together.
- Analysis report fallback now prefers the most recent compatible config-hash entry instead of the first registry hit.
- Hiding the detail panel no longer clears active graph highlight or edge-flow animation; only explicit clear actions reset graph context.
- Mobile and tablet subgraph detail views no longer obscure most of the canvas and can be restored without losing the inspected logic flow.

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
