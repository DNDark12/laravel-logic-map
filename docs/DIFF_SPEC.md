# DIFF SPEC — v1.2.0 Contract Hardening + Resolver Lifecycle + Analysis UX + UI Polish

## Scope

This diff captures the `v1.2.0` release without reopening the query-layer split.

Included work:

- contract hardening for JSON endpoints and export aliasing
- resolver lifecycle hardening and active-pointer semantics
- analysis UX additions (`/hotspots`, payload builders)
- viewer export affordances and token-based theme wiring
- public package docs/changelog updates

Excluded work:

- AST parser changes
- analyzer rule changes
- graph domain redesign
- cross-browser QA matrix

## API contract changes

### JSON envelope

All JSON endpoints continue to return:

```json
{
  "ok": true,
  "data": {},
  "message": null,
  "errors": null
}
```

Error responses now consistently use typed `errors[]` entries and keep `_resolution` inside `data` whenever snapshot resolution was attempted.

### Resolution metadata

Snapshot-backed endpoints now expose:

- `requested_snapshot`
- `resolved_via`
- `resolved_fingerprint`
- `pointer_state`
- `analysis_state`

### Export alias

`GET /logic-map/export/json` remains supported, but is now formally deprecated in favor of `/logic-map/export/bundle` and emits:

- `X-Logic-Map-Deprecated: true`
- `X-Logic-Map-Replacement: /logic-map/export/bundle`

### Export metadata

`graph.metadata.generated_at` is now sourced from persisted snapshot metadata so repeated reads are deterministic for the same snapshot.

## Repository and resolver lifecycle

### Repository contract

`GraphRepository` now supports:

- `getSnapshotMetadata(string $fingerprint): array`
- `setActiveFingerprint(string $fingerprint): void`

### Pointer semantics

- `putSnapshot()` updates the stored snapshot and latest fingerprint only.
- `BuildLogicMapService` promotes a fingerprint to active only after graph + analysis persistence succeeds.
- `current_fingerprint` remains the public alias of `active_fingerprint`.

### Strict mode

New config:

```php
'query' => [
    'resolver' => [
        'strict_resolution' => false,
    ],
],
```

When enabled:

- missing pointers do not fall back
- corrupted pointers do not fall back
- analysis-backed reads fail fast when the resolved snapshot has no analysis report

### Logging

Resolver now emits warnings for:

- active pointer missing
- active pointer corrupted
- graph snapshot available but analysis missing

## Analysis UX

### New endpoint

`GET /logic-map/hotspots`

Supported filters:

- `snapshot`
- `kind`
- `module`
- `risk`
- `limit`

Sorting order:

1. `risk_score desc`
2. `coupling desc`
3. `instability desc`
4. `fan_out desc`
5. `node_id asc`

### Service split inside analysis read path

`AnalysisReadService` now delegates payload construction to:

- `HealthPayloadBuilder`
- `HotspotsBuilder`

This keeps the service orchestration-focused and makes hotspot/health logic independently testable.

## UI changes

### Viewer config

`window.logicMapConfig` now exposes:

- `hotspotsUrl`
- `exportGraphUrl`
- `exportAnalysisUrl`
- `exportBundleUrl`
- `exportJsonUrl`
- `exportCsvUrl`

### Export controls

Desktop export dropdown and mobile actions menu now expose explicit actions for:

- Export Graph JSON
- Export Analysis JSON
- Export Bundle JSON
- Export CSV

### Theme tokens

The viewer now reads semantic CSS variables for:

- canvas
- node colors by kind
- edge colors
- risk colors
- heatmap colors

Cytoscape styles are rebuilt from those tokens when the theme changes.

## Verification

Implemented verification for this diff includes:

- package feature/unit tests for resolver lifecycle, exports, hotspots, and builders
- live HTTP checks for `/export/json` and `/hotspots`
- Chromium smoke checks at `1366`, `1024`, `768`, and `430`
- zero browser console errors during smoke
