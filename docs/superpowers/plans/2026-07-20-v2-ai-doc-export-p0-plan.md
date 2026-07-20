# V2 AI Documentation Export P0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore correct V2 module/class workflow CLI projection and a safe batch Markdown export for AI-readable module and workflow dossiers.

**Architecture:** Dispatch workflow output from the resolved V2 node kind, reuse existing V2 projectors, and add only the module/dossier composition missing from V2. Batch export reads the active snapshot and writes deterministic repository-relative files without invoking impact analysis.

**Tech Stack:** PHP 8.2+, Laravel Artisan, PHPUnit/Testbench, V2 `GraphSnapshot`, `ModuleWorkflow`, and existing JSON/Markdown/Mermaid projectors.

---

### Task 1: Lock CLI module and collection parity

**Files:**
- Modify: `tests/Feature/V2CommandSurfaceTest.php`
- Modify: `src/Commands/WorkflowLogicMapCommand.php`

- [ ] Add failing command tests asserting `module:Orders` returns `workflow_type=module` with more than one entry workflow and a controller returns `workflow_type=symbol_collection`.
- [ ] Run `vendor/bin/phpunit tests/Feature/V2CommandSurfaceTest.php` and verify the current one-node projection fails.
- [ ] Dispatch module and container selections to `buildModule()` and `buildSymbolCollection()` while retaining ordinary workflow behavior.
- [ ] Re-run the focused command tests and verify they pass.

### Task 2: Add module and workflow dossier Markdown

**Files:**
- Create: `src/Projectors/ModuleWorkflowMarkdownProjector.php`
- Create: `src/Projectors/WorkflowDossierMarkdownProjector.php`
- Create: `tests/Unit/Projectors/ModuleWorkflowMarkdownProjectorTest.php`

- [ ] Add failing projector tests requiring schema/snapshot metadata, all module entrypoints, relations, shared resources, gaps, and fenced Mermaid diagrams.
- [ ] Run the projector test and verify the classes are missing.
- [ ] Implement deterministic composition from `ModuleWorkflowJsonProjector`, `WorkflowMarkdownProjector`, and `WorkflowMermaidProjector`.
- [ ] Re-run projector tests and verify they pass.

### Task 3: Restore V2-native batch documentation command

**Files:**
- Create: `src/Commands/ExportDocsCommand.php`
- Modify: `src/LogicMapServiceProvider.php`
- Modify: `config/logic-map.php`
- Modify: `tests/Feature/DocumentationCommandContractTest.php`
- Create: `tests/Feature/ExportDocsCommandTest.php`

- [ ] Add failing tests requiring command registration, deterministic overview/module/workflow files, overwrite protection, and repository path safety.
- [ ] Run the two feature test files and verify `logic-map:export-docs` is absent.
- [ ] Implement the command using the active snapshot, module enumeration, `buildModule()`, and `SafeOutputWriter`.
- [ ] Register the command and add bounded export defaults.
- [ ] Re-run the focused feature tests and verify they pass.

### Task 4: Contract cleanup and verification

**Files:**
- Modify: `tests/Feature/V1RemovalContractTest.php`
- Modify: `README.md`

- [ ] Update the removal contract so only truly removed V1 surfaces remain forbidden.
- [ ] Document the P0 export command and exact generated files.
- [ ] Run `vendor/bin/phpunit` and verify all tests pass.
- [ ] Run `git diff --check` and inspect the final diff for V1 contract leakage or calls to `ImpactQueryService` from doc export.
