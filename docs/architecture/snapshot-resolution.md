# Architecture: Snapshot Resolution

## The Resolution Engine
The Query Pipeline uses a pointer-based resolution strategy to serve graph and analysis data with zero AST parsing overhead on the request path.

### Resolution Metadata (`_resolution`)
Every successful JSON response includes a `_resolution` object to clarify the provenance of the data:

- `requested_snapshot`: The specific fingerprint from a `snapshot=...` query param.
- `resolved_via`: The strategy used (`active_pointer`, `latest_snapshot_fallback`, or `requested_snapshot`).
- `resolved_fingerprint`: The final fingerprint used for the response.
- `pointer_state`:
    - `ok`: Found the active pointer successfully.
    - `missing`: No active pointer found; fell back to latest.
    - `corrupted`: Pointer existed but the file was missing/binary corrupted; fell back to latest.
    - `bypassed`: Explicitly requested a snapshot via URL parameters.
- `analysis_state`:
    - `available`: Analysis report found for this graph.
    - `missing`: Graph exists but analysis results are not present.
    - `not_requested`: Endpoint only serves graph data.
    - `unresolved`: General failure to resolve analysis metadata.

## Fallback Semantics
If the `active_pointer` is missing or corrupted, the resolver will automatically attempt to find the latest valid cached snapshot from the repository's `registry.json`. This ensures the Viewer remains functional even if a build fails mid-process or a pointer file is accidentally deleted.
