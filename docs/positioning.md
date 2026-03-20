# Product Positioning

**Laravel Logic Map** is NOT a generic diagramming tool. It is a specialized **Change Intelligence** and **Workflow Understanding** package tailored specifically for Laravel.

## Target Audience
- **PM / CTO / Tech Leads:** Visualize system complexity, assess architecture health, and review blast radius before approving major releases.
- **Engineers:** Onboard faster, trace execution flows, and confidently refactor legacy code without breaking decoupled components.
- **AI Assistants (Claude, Cursor, etc.):** Ingest token-efficient, deterministic facts (`llms.txt`, Workflow Dossiers) instead of hallucinating Laravel magic (Events, Jobs, Facades).

## Core Principles
1. **Fact over Fiction:** We rely on deterministic static AST parsing (`nikic/php-parser`), not regex or LLM guessing.
2. **Performance by Default:** Heavy analysis happens in `artisan logic-map:build`. The UI and AI agents query cached snapshots in milliseconds.
3. **Workflow-Centric:** We care about how logic actually executes (Route → Controller → Service → Event), not just static namespace diagrams.
