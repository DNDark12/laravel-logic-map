<p align="center">
  <img src="https://raw.githubusercontent.com/DNDark12/laravel-logic-map/main/art/logo.png" alt="Laravel Logic Map Logo" width="600">
</p>

# Laravel Logic Map

<p align="center">
  <strong>Understand, audit, and visualize your Laravel application's workflows, change impact, and architectural risk.</strong>
</p>

**Laravel Logic Map** is a local-first Laravel package that maps how logic moves through your app — from routes to controllers, services, jobs, events, and persistence. 

It provides humans and AI assistants with a deterministic, AST-powered single source of truth for architectural dependencies and execution flows.

## Key Features

- **Workflow Visualization**: Interactive rendering of your Laravel application's true execution paths.
- **Change Intelligence**: Understand the exact **Blast Radius** (impact) and execution **Trace** of any class, method, or route.
- **Deterministic AST Analysis**: Uses `nikic/php-parser` to extract code structure without runtime performance hits.
- **AI-Ready Documentation**: Export your codebase logic into token-efficient `llms.txt` and Markdown Workflow Dossiers optimized for LLM consumption.

## Installation

```bash
composer require dndark/laravel-logic-map --dev
php artisan vendor:publish --tag=logic-map-config
php artisan vendor:publish --tag=logic-map-full
```

## Commands

```bash
php artisan logic-map:build          # Build graph snapshot
php artisan logic-map:analyze        # Re-run architectural analysis
php artisan logic-map:export-docs    # Export workflow dossiers & llms.txt context
php artisan logic-map:export-note    # Export a node's Impact/Trace report
php artisan logic-map:clear-cache    # Clear cached snapshots
```

**Access the UI at:** `/logic-map`

## API Endpoints

- `GET /logic-map/overview` — Full graph
- `GET /logic-map/subgraph/{id}` — Node neighborhood
- `GET /logic-map/impact/{id}` — Impact blast radius (JSON)
- `GET /logic-map/trace/{id}` — Workflow trace traversal (JSON)
- `GET /logic-map/reports/impact/{id}` — HTML Impact Report UI
- `GET /logic-map/reports/trace/{id}` — HTML Trace Report UI
- *And various JSON/CSV/Markdown export lines...*

## ⌨️ Keyboard Shortcuts

| Key | Action |
| --- | --- |
| `1` | Graph Mode (Full map) |
| `2` | Flow Mode (Request paths only) |
| `3` | Risk Mode (Audit hotspots) |
| `4` | Zones Mode (High-level module boundaries) |
| `F` | Fit graph to view |
| `S` | SubGraph mode (on selected node) |
| `H` | Toggle Complexity Heatmap |
| `M` | Toggle Module Explorer |
| `T` | Cycle Themes |
| `⌘K`| Focus search |
| `Esc`| Close panel / Exit SubGraph |
| `?` | Show shortcuts modal |

## 📊 View Modes

Laravel Logic Map provides 4 distinct semantic view modes to help answer different architectural questions:

1. **Graph Mode (`1`)**: Full dependency map. Shows all nodes and all edges perfectly as parsed.
2. **Flow Mode (`2`)**: Workflow paths. Hides utility classes/enums and drops messy edges (like simple model imports) to visualize how requests actually travel through code.
3. **Risk Mode (`3`)**: Hotspot audit. Isolates the top 30% most coupled & complex nodes and expands their 1-hop context.
4. **Zones Mode (`4`)**: Module overview. Aggregates all nodes into high-level logical "supernodes" per module. Double-click a zone to instantly isolate it and see its internal Flow.

## Requirements

- PHP 8.2+
- Laravel 10 / 11 / 12

## License
The MIT License (MIT). Please see [License](LICENSE) for more information.
