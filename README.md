# Laravel Logic Map

> Understand, audit, and visualize your Laravel application's logic and workflows.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dndark/laravel-logic-map.svg)](https://packagist.org/packages/dndark/laravel-logic-map)
[![Tests](https://img.shields.io/github/actions/workflow/status/dndark/laravel-logic-map/tests.yml?label=tests)](https://github.com/dndark/laravel-logic-map/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Laravel Logic Map uses deterministic AST analysis (`nikic/php-parser`) + Laravel runtime metadata to build an interactive, layered map of how logic flows through your application — routes, controllers, services, jobs, events, and models.

## Features

- **Workflow graph** — Route → Controller → Service → Job → Model (not just dependencies)
- **7 structural metrics** — in/out degree, fan in/out, instability, coupling, depth
- **5 built-in analyzers** — fat controllers, circular dependencies, orphans, high instability, high coupling
- **Health scoring** — A-F grade with explainable risk per node
- **Export** — JSON & CSV download for CI integration
- **Interactive UI** — Cytoscape.js graph viewer at `/logic-map`
- **Build-time analysis** — cached snapshots, zero runtime overhead

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require dndark/laravel-logic-map --dev
```

The service provider is auto-discovered. Publish the config and assets:

```bash
php artisan vendor:publish --tag=logic-map-config
php artisan vendor:publish --tag=logic-map-assets
```

## Quick Start

```bash
# Build the graph snapshot
php artisan logic-map:build

# Open the UI
# Visit http://your-app.test/logic-map
```

## CLI Commands

| Command | Description |
|---------|-------------|
| `logic-map:build` | Scan project and build graph snapshot |
| `logic-map:build --force` | Force rebuild (ignore cache) |
| `logic-map:analyze` | Re-run analysis without rebuilding |
| `logic-map:analyze --show-violations` | Show violation details |
| `logic-map:clear-cache` | Clear all cached snapshots |

## API Endpoints

All endpoints return `{ ok, data, message, errors }` envelope.

| Endpoint | Description |
|----------|-------------|
| `GET /logic-map/overview` | Full graph (nodes + edges) |
| `GET /logic-map/subgraph/{id}` | Node neighborhood |
| `GET /logic-map/search?q=` | Search nodes by name |
| `GET /logic-map/meta` | Graph statistics |
| `GET /logic-map/violations` | Architecture violations |
| `GET /logic-map/health` | Health score + grade |
| `GET /logic-map/export/json` | Full export (graph + analysis) |
| `GET /logic-map/export/csv` | Node metrics CSV download |

### Filters

- `/violations?severity=critical` — filter by severity
- `/violations?type=fat_controller` — filter by type
- `/overview?kinds[]=controller&kinds[]=service` — filter by node kind

## Configuration

```php
// config/logic-map.php

'scan_paths' => [app_path()],

'analysis' => [
    'enabled' => true,
    'thresholds' => [
        'fat_controller_fan_out' => 10,
        'high_instability'       => 0.9,
        'high_coupling'          => 20,
    ],
    'analyzers' => [
        'fat_controller'      => true,
        'circular_dependency' => true,
        'orphan'              => true,
        'high_instability'    => false, // enable for medium-severity checks
        'high_coupling'       => false,
    ],
],
```

## Architecture

```
Build Pipeline (artisan logic-map:build)
├── FileDiscovery → find PHP files
├── AstParser → extract nodes & edges → Graph
├── MetricsCalculator → 7 metrics per node
├── ArchitectureAnalyzer → violations → AnalysisReport
└── CacheGraphRepository → store snapshot + report

Query Pipeline (HTTP requests)
├── QueryLogicMapService → fetch from cache
├── Projectors → overview, subgraph, search, meta
└── LogicMapController → JSON envelope
```

## Testing

```bash
composer test              # all tests
composer test:unit         # unit only
composer test:feature      # feature / integration only
```

## License

MIT — see [LICENSE](LICENSE).
