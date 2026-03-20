# Architecture

Laravel Logic Map uses a two-phase architecture to separate heavy static analysis from fast UI queries:

## 1. Build Pipeline (`logic-map:build` & `logic-map:analyze`)
- Extracts graph data using `nikic/php-parser` (AST).
- Computes structural metrics (coupling, instability, fat controllers).
- Caches the immutable graph snapshot and analysis results using a deterministic file fingerprint.

## 2. Query Pipeline (API & HTTP Reports)
- Resolves the active (or requested) snapshot without reparsing code.
- Serves localized projections (Overview, Subgraph, Search).
- Computes advanced traversals (**Impact Blast Radius** and **Workflow Trace**) on-the-fly from the cached graph.

## 3. Artifact Generation (`logic-map:export-docs` & `logic-map:export-note`)
- Consumes the Query Pipeline to generate static Markdown representations of the logic.
- Generates `llms.txt` and **Workflow Dossiers** optimized for AI context limits.
- Generates node-specific Markdown reports for deep-dive human and machine analysis.
