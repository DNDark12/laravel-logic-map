# Evidence and certainty

Laravel Logic Map records evidence for graph relationships rather than treating every static or runtime observation as equally proven.

## Evidence origin

- `static_ast` — source structure collected by the PHP AST pipeline.
- `laravel_boot` — route, container, event, command, policy, or schedule facts collected from Laravel.
- `runtime` — an opt-in sanitized observation attached to the same snapshot.
- `git_diff` — source information used to resolve a Git-range impact query.

Runtime evidence augments a query-time relation only when snapshot, source, target, and relation kind are compatible. A runtime-only observation stays `runtime_only`; temporal proximity never becomes a dependency.

## Static certainty

- `certain` — a stable source, target, and typed relation were resolved.
- `probable` — strong source evidence exists, but resolution is incomplete.
- `possible` — a bounded best-effort inference needs review.

No evidence is not evidence of absence. Dynamic container resolution, reflection, generated routes, dynamic method calls, and opaque package internals can remain unresolved; workflow and impact projections show gaps and truncation rather than treating unknown paths as impossible.

## Reading output

Evidence IDs identify the detector, source location when available, certainty, and bounded attributes that explain a relation. Treat `certain` paths as high-confidence scope; treat `probable` and `possible` paths as review prompts. Runtime coverage describes observed behavior in selected sessions, not proof of causality or non-execution.
