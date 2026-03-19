# Bug Report: Detail Panel Hide vs Clear Coupling

## Summary
On compact screens, opening a node detail panel could obscure most of the canvas. Closing the panel removed the active highlight and stopped animated flow, so users lost the logic path they were inspecting.

## Root Cause
The viewer treated `closePanel()` as both a presentation action and a state reset action. Hiding the panel also called `stopFlow()` and removed `highlighted`, `neighbor`, and `dimmed` classes from Cytoscape elements.

## Impact
- Mobile users could not inspect a subgraph without losing most of the viewport.
- Desktop and mobile shared the same coupling, so hiding detail always destroyed context.
- Users had no compact or restore state for the selected node.

## Fix
- Introduced panel states: `expanded`, `peek`, `hidden`.
- Re-defined `closePanel()` as a compatibility alias for `hidePanel()`.
- Moved graph reset responsibility into `clearHighlight()` only.
- Added restore affordance so detail can be reopened while keeping the active flow visible.
- Switched compact viewports to a bottom-sheet `peek` state instead of a full-screen side sheet.

## Verification
- Feature regression for panel-state controls in the rendered viewer shell.
- Browser smoke on desktop and mobile breakpoints for hide, restore, and subgraph flows.
