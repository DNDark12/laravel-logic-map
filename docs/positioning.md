# Product positioning

Laravel Logic Map is a Laravel semantic workflow and change-impact engine, not a generic class diagram generator.

Its primary job is to reduce the uncertainty around a change:

- show the full execution workflow rather than one direct call chain;
- connect routes, validation, authorization, services, branches, transactions, events, jobs, listeners, state effects, and external effects;
- explain why a module/function is affected with an ordered evidence chain;
- distinguish certain, probable, possible, unresolved, and runtime-observed facts;
- expose machine-readable contracts for engineers and AI coding tools.

## Intended users

- Engineers planning or reviewing risky changes in Laravel systems.
- Tech leads identifying affected modules, contracts, and test scope before implementation.
- Maintainers onboarding into event-driven or legacy applications.
- AI assistants that need deterministic repository facts instead of guessed framework behavior.

## Product principles

1. Evidence over visual confidence: every meaningful relation must be explainable.
2. Impact over inventory: a graph is useful only when it helps scope a real change.
3. Laravel semantics over generic PHP calls: framework registrations and effects are first-class.
4. Unknown stays unknown: missing observation is not proof of non-execution.
5. Local-first and bounded: indexing and optional runtime evidence stay in the consumer project.

## Non-goals for 2.0

- whole-program proof for arbitrary reflection or generated code;
- production APM or distributed tracing replacement;
- automatic code modification;
- V1 route, cache, command, UI, or snapshot compatibility;
- inference of causal dependencies from timestamps alone.
