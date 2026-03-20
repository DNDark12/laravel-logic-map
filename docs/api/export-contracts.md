# API: Export Contracts

## Overview
Laravel Logic Map provides multiple export contracts designed for both human consumption and machine ingestion (AI assistants).

### 1. Graph Export (`/export/graph`)
**Purpose**: Canonical graph data for visualization or structural analysis.
- **Format**: JSON
- **Keys**: `nodes[]`, `edges[]`, `metadata`
- **Semantics**: Represents the structural connectivity of the application logic.

### 2. Analysis Export (`/export/analysis`)
**Purpose**: Derived intelligence based on graph metrics.
- **Format**: JSON
- **Keys**: `health_score`, `grade`, `summary`, `violations[]`, `node_risk_map{}`
- **Semantics**: Provides insights into coupling, risk, and architectural violations.

### 3. Bundle Export (`/export/bundle`)
**Purpose**: Complete system dossier in a single payload.
- **Format**: JSON
- **Keys**: `graph`, `analysis`
- **Semantics**: The primary artifact for AI assistant ingestion.

### 4. CSV Export (`/export/csv`)
**Purpose**: Flat data for spreadsheet analysis (Excel/Google Sheets).
- **Format**: CSV
- **Semantics**: Export node list with basic metrics (`kind`, `id`, `risk_score`).

## Deprecated Alias: `/export/json`
In `v1.2.0`, `/export/json` acts as an alias for the **Bundle Export**. This endpoint is deprecated and will be removed in `v2.0`. Consumers should migrate to `/export/bundle`.
