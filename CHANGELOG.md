# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
- **3 CLI Commands**: `logic-map:build`, `logic-map:analyze`, `logic-map:clear-cache`
- **Interactive UI**: Cytoscape.js graph viewer
- **Build-time caching**: Fingerprint-based cache with compound analysis key
- **101 tests** with 724 assertions
