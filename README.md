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

- Current release tag: `v1.1.5`
- Scope: Package reliability fixes (namespace case-sensitivity), longer snapshot retention, and release metadata refresh

## 📂 Architecture

Laravel Logic Map consists of two high-performance pipelines:

*   **Build Pipeline**: Scans files using a custom AST parser, calculates structural metrics, and runs the health analyzer. Results are cached as a binary snapshot (Fingerprint indexed).
*   **Query Pipeline**: A projection-based API layer that serves cached graph data and analysis reports to the Cytoscape.js frontend with no rebuild and no AST parse on the request path.

## Key Features

- End-to-end workflow graph with interactive Cytoscape viewer
- Metrics and risk insights (in/out degree, fan in/out, instability, coupling, depth)
- Health panel with score/grade and explainable violations
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
| `⌘K` / `/` | Focus search |
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
| `GET /logic-map/export/graph` | Canonical graph export (`graph`) |
| `GET /logic-map/export/analysis` | Derived analysis export (`analysis`) |
| `GET /logic-map/export/bundle` | Graph + analysis bundle export |
| `GET /logic-map/export/json` | Alias of bundle export |
| `GET /logic-map/export/csv` | CSV export |

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
      "resolved_via": "active_pointer",
      "resolved_fingerprint": null,
      "pointer_state": "missing"
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

Successful JSON reads include `data._resolution` so consumers can see whether the response came from:

- `requested_snapshot`
- `active_pointer`
- `latest_snapshot_fallback`

`current_fingerprint` now follows the active snapshot pointer semantics and is always equal to `active_fingerprint`.

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
```

## License
The MIT License (MIT). Please see [License](LICENSE) for more information.
