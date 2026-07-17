# Architecture

Laravel Logic Map 2.0 separates immutable indexing from bounded query-time projections.

## Index pipeline

```text
RepositoryFileDiscovery
  -> ParsePhpPhase
  -> ResolvePhpPhase
  -> CollectLaravelBootFactsPhase
  -> ExtractLaravelSemanticsPhase
  -> BuildProcessMembershipPhase
  -> GraphSnapshot
  -> SqliteGraphRepository
```

The parser emits structural PHP facts and Laravel-specific facts. Resolvers turn those facts into stable nodes, typed edges, diagnostics, process steps, and evidence records. The source fingerprint includes the analysis and schema versions, so detector/process semantic changes invalidate stale snapshots even when source files did not change.

## Stable identities

Canonical node IDs use closed prefixes such as:

```text
route:POST:orders/{order}/cancel
method:App\Services\OrderService::cancel
class:App\Jobs\CancelOrder
table:orders
column:orders.status
module:Orders
process:route:POST:orders/{order}/cancel
```

Edges are typed (`calls`, `dispatches`, `writes_table`, `calls_external`, and others) and reference one or more evidence records. Node/edge IDs and canonical JSON order are deterministic.

## Query layer

- `SymbolContextService` returns bounded incoming/outgoing relations, processes, modules, effects, and evidence.
- `WorkflowBuilder` traverses execution-relevant directions and preserves decisions, terminals, cycles, transactions, async boundaries, gaps, and frontier truncation.
- `ImpactAnalyzer` maps a direct symbol or Git diff into reason-grouped affected symbols, shared resources, modules, uncertainty, and selected tests.
- Projectors produce JSON, Markdown, or Mermaid without reparsing source.

## Runtime overlay

Runtime sessions and observations live beside snapshots in SQLite but do not mutate them. `RuntimeEvidenceMerger` accepts a runtime relation only when:

1. the session snapshot matches the queried snapshot;
2. source and target are valid stable node IDs present in that snapshot;
3. observation kind exactly maps to a supported edge type;
4. the session is in the selected session set.

Matching evidence augments a query-time relation. Runtime-only evidence remains explicitly `runtime_only` with no `static_certainty`. Temporal adjacency is never promoted into a dependency.

## Storage and bounds

SQLite owns snapshots, nodes, edges, evidence, diagnostics, process steps, runtime sessions, and runtime observations. Query depth, node/edge count, response bytes, session retention, session capacity, and observations per session are bounded by config. `logic-map:clear --force` removes only the configured package database.

## Security boundaries

- HTTP access is environment- and middleware-gated.
- Git refs and output paths are validated.
- Runtime attributes are allowlisted, bounded, and secret-redacted.
- SQL bindings, request bodies, response bodies, cache values, and arbitrary event payloads are not stored.
