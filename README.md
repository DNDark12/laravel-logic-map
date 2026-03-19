<p align="center">
  <img src="art/logo.png" width="200" alt="Laravel Logic Map Logo">
</p>

<p align="center">
  <strong>Understand, analyze, and visualize your application's architecture and logic flows.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/dndark/laravel-logic-map"><img src="https://img.shields.io/packagist/v/dndark/laravel-logic-map.svg" alt="Latest Version on Packagist"></a>
  <a href="https://github.com/dndark12/laravel-logic-map/actions"><img src="https://img.shields.io/github/actions/workflow/status/dndark/laravel-logic-map/tests.yml?label=tests" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License: MIT"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-%5E8.2-777bb4.svg" alt="PHP Version"></a>
</p>

---

Laravel Logic Map combines deterministic AST analysis (`nikic/php-parser`) with Laravel runtime metadata to build an interactive map of logic flow:

`Route -> Controller -> Service -> Job/Event -> Persistence`

## Latest Release

- Current release tag: `v1.2.1`
- Release highlights: contract hardening, explicit active snapshot lifecycle, hotspots API, export contract split, and responsive viewer polish

## 📂 Architecture

Laravel Logic Map consists of two high-performance pipelines:

*   **Build Pipeline**: Scans files using a custom AST parser, calculates structural metrics, enriches runtime metadata, runs the health analyzer, and persists graph + analysis artifacts.
*   **Query Pipeline**: A projection-based API layer that serves cached graph data and analysis reports via pointer-based snapshot resolution with no rebuild and no AST parse on the request path.

See [`docs/architecture.md`](docs/architecture.md) for the build-vs-query flow and [`docs/troubleshooting.md`](docs/troubleshooting.md) for resolver states, fallback behavior, and strict-resolution debugging.

## Key Features

- End-to-end workflow graph with interactive Cytoscape viewer
- Metrics and risk insights (in/out degree, fan in/out, instability, coupling, depth)
- Health panel with score/grade and explainable violations
- Hotspots API for top risky nodes (`/hotspots`)
- Subgraph exploration with depth controls and zero-reload exit
- Snapshot time-travel and graph diff (`/snapshots`, `/diff`)
- Complexity heatmap toggle
- Export canonical graph, derived analysis, bundle JSON, and node metrics CSV
- Publishable views/assets for team-level UI customization

## Installation

```bash
composer require dndark/laravel-logic-map --dev
```

### Publish Resources

```bash
php artisan vendor:publish --tag=logic-map-config
php artisan vendor:publish --tag=logic-map-full
```

## Quick Start

1. Build snapshot:

```bash
php artisan logic-map:build --force
```

2. Open UI:

`http://your-app.test/logic-map`

## ⌨️ Keyboard Shortcuts

| Key | Action |
| --- | --- |
| `1`-`4` | Switch Layouts (Dagre, Force, LR, Compact) |
| `F` | Fit graph to view |
| `S` | SubGraph mode (on selected node) |
| `H` | Toggle Complexity Heatmap |
| `M` | Toggle Module Explorer |
| `T` | Cycle Themes |
| `⌘K` | Focus search |
| `Esc` | Close panel / Exit SubGraph |
| `?` | Show shortcuts modal |

## API Endpoints

| Endpoint | Description |
| --- | --- |
| `GET /logic-map` | Viewer shell |
| `GET /logic-map/overview` | Overview projection |
| `GET /logic-map/subgraph/{id}` | Node neighborhood |
| `GET /logic-map/search?q=` | Node search |
| `GET /logic-map/meta` | Meta/statistics |
| `GET /logic-map/snapshots` | Snapshot list with `latest_fingerprint`, `active_fingerprint`, `current_fingerprint` and per-item `is_latest`, `is_current`, `is_active` |
| `GET /logic-map/diff` | Snapshot diff |
| `GET /logic-map/health` | Health score/grade |
| `GET /logic-map/violations` | Violation list/summary |
| `GET /logic-map/hotspots` | Top risky nodes with filters (`kind`, `module`, `risk`, `limit`) |
| `GET /logic-map/export/graph` | Canonical graph export (`graph`) |
| `GET /logic-map/export/analysis` | Derived analysis export (`analysis`) |
| `GET /logic-map/export/bundle` | Graph + analysis bundle export |
| `GET /logic-map/export/json` | Deprecated alias of bundle export; use `/logic-map/export/bundle` |
| `GET /logic-map/export/csv` | CSV export |

### Deprecated Bundle Alias

`GET /logic-map/export/json` remains available in `v1.2.0`, but every response now includes:

```text
X-Logic-Map-Deprecated: true
X-Logic-Map-Replacement: /logic-map/export/bundle
```

Plan migrations against `/logic-map/export/bundle`. The alias will stay supported until at least `v2.0`.

### JSON Envelope

All JSON endpoints use the envelope:

```json
{
  "ok": true,
  "data": {},
  "message": null,
  "errors": null
}
```

Error responses use typed `errors` entries:

```json
{
  "ok": false,
  "data": {
    "_resolution": {
      "requested_snapshot": null,
      "resolved_via": "active_pointer",
      "resolved_fingerprint": null,
      "pointer_state": "missing",
      "analysis_state": "unresolved"
    }
  },
  "message": "No snapshot found. Run `php artisan logic-map:build` first.",
  "errors": [
    {
      "type": "snapshot_not_found",
      "detail": "No snapshot found. Run `php artisan logic-map:build` first."
    }
  ]
}
```

### Resolver Metadata

Successful JSON reads include `data._resolution` so consumers can see how the payload was resolved:

- `requested_snapshot`: explicit snapshot query parameter, echoed back when provided
- `resolved_via=active_pointer`: response used the configured active snapshot pointer
- `resolved_via=latest_snapshot_fallback`: active pointer was missing/corrupted and resolver fell back to the latest valid snapshot
- `resolved_via=requested_snapshot`: explicit snapshot query parameter bypassed pointer resolution
- `pointer_state=ok|missing|corrupted|bypassed`
- `analysis_state=available|missing|not_requested|unresolved`

`current_fingerprint` follows the effective active snapshot semantics and is always equal to `active_fingerprint`.

### Export Examples

`GET /logic-map/export/graph`

```json
{
  "ok": true,
  "data": {
    "graph": {
      "nodes": [],
      "edges": [],
      "metadata": {
        "fingerprint": "fp_123",
        "generated_at": "2026-03-19T10:00:00+00:00"
      }
    },
    "_resolution": {
      "requested_snapshot": null,
      "resolved_via": "active_pointer",
      "resolved_fingerprint": "fp_123",
      "pointer_state": "ok",
      "analysis_state": "not_requested"
    }
  },
  "message": null,
  "errors": null
}
```

`GET /logic-map/export/analysis`

```json
{
  "ok": true,
  "data": {
    "analysis": {
      "health_score": 88,
      "grade": "B",
      "summary": {},
      "violations": [],
      "node_risk_map": {},
      "metadata": {
        "graph_fingerprint": "fp_123"
      }
    },
    "_resolution": {
      "requested_snapshot": null,
      "resolved_via": "active_pointer",
      "resolved_fingerprint": "fp_123",
      "pointer_state": "ok",
      "analysis_state": "available"
    }
  },
  "message": null,
  "errors": null
}
```

`GET /logic-map/export/bundle`

```json
{
  "ok": true,
  "data": {
    "graph": {
      "nodes": [],
      "edges": [],
      "metadata": {
        "fingerprint": "fp_123",
        "generated_at": "2026-03-19T10:00:00+00:00"
      }
    },
    "analysis": {
      "health_score": 88,
      "grade": "B",
      "summary": {},
      "violations": [],
      "node_risk_map": {},
      "metadata": {
        "graph_fingerprint": "fp_123"
      }
    },
    "_resolution": {
      "requested_snapshot": null,
      "resolved_via": "active_pointer",
      "resolved_fingerprint": "fp_123",
      "pointer_state": "ok",
      "analysis_state": "available"
    }
  },
  "message": null,
  "errors": null
}
```

## How To Read The Metrics

| Metric | Meaning | How to interpret |
| --- | --- | --- |
| `fan_in` | Number of incoming dependencies | High values often indicate hubs or shared utilities |
| `fan_out` | Number of outgoing dependencies | High values often indicate orchestration or coupling debt |
| `instability` | Tendency to change because it depends on many volatile parts | Values near `1.0` indicate fragile, outward-dependent code |
| `coupling` | Total structural connectedness | High values mean harder isolation and testing |
| `depth` | Distance from route entrypoints | `null` means unreachable from configured entrypoints |

Structural metrics are facts derived from the graph. Risk, health score, and violations are derived insights layered on top of those facts.

## Configuration

Tune analysis and thresholds in `config/logic-map.php`:

```php
'analysis' => [
    'enabled' => true,
    'thresholds' => [
        'fat_controller_fan_out' => 10,
        'high_instability'       => 0.9,
    ],
    'analyzers' => [
        'fat_controller'      => true,
        'circular_dependency' => true,
        'orphan'              => true,
        'dead_code'           => true,
    ],
],
'query' => [
    'resolver' => [
        'strict_resolution' => false,
        'fallback_on_missing_pointer' => true,
        'fallback_on_corrupted_pointer' => true,
    ],
],
```

## License
The MIT License (MIT). Please see [License](LICENSE) for more information.
