# V2 AI Documentation Export P0 Design

## Problem

Laravel Logic Map V2 removed the V1 `logic-map:export-docs` command. The remaining
`logic-map:workflow` command treats a module as one ordinary node, even though the
HTTP API already projects a module through `WorkflowQueryService::buildModule()`.
This makes module documentation incomplete and causes `module:Orders` to appear as
a one-step workflow.

## Scope

P0 restores a V2-native documentation path without restoring V1 graph or report
contracts:

- CLI workflow projection recognizes modules and class/container collections.
- Module Markdown summarizes all entry workflows and embeds their Mermaid diagrams.
- `logic-map:export-docs` writes a deterministic project overview, module dossiers,
  and entry-workflow dossiers from the active V2 snapshot.
- Module documentation uses `buildModule()` and never models every module member as
  a changed symbol.

Out of scope: `llms.txt`, manifest/checksums, Git-range change dossiers, incremental
cleanup, browser export, and full node catalogs.

## Architecture

`WorkflowLogicMapCommand` dispatches by selected node kind. Ordinary entrypoints use
the existing `WorkflowDefinition` projectors. Modules use `ModuleWorkflow` plus a new
`ModuleWorkflowMarkdownProjector`. Class-like containers use the existing symbol
collection JSON projector; Markdown export iterates their entry workflows.

`ExportDocsCommand` is an orchestration-only command. It reads the active snapshot,
enumerates module nodes, builds each module through `WorkflowQueryService`, and writes
files through `SafeOutputWriter`. Workflow Markdown reuses the existing Markdown and
Mermaid projectors, so the exported semantics remain identical to CLI/API results.

## Output Contract

```text
docs/logic-map/
├── overview.md
├── modules/<module-slug>.md
└── workflows/<entrypoint-slug>.md
```

Every file records schema version and snapshot ID. Module dossiers contain summary,
inbound/outbound relations, shared resources, gaps, an entrypoint index, and Mermaid
diagrams. Workflow dossiers contain the existing V2 Markdown plus an embedded Mermaid
section.

## Safety and Determinism

- Default output remains repository-relative.
- Existing files require `--force`.
- Slugs derive deterministically from canonical IDs.
- Modules and workflows are sorted by canonical ID.
- Export never invokes `ImpactQueryService` for baseline module documentation.

## Verification

- A module workflow CLI result has multiple entry workflows in the Commerce fixture.
- Module Markdown includes multiple entrypoints and Mermaid.
- `export-docs` creates overview/module/workflow files and does not call module impact.
- Unsafe paths and accidental overwrites fail.
- Full package suite remains green.
