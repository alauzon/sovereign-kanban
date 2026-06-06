# Documentation — Sovereign Kanban

This folder is the **living brain of the project**. It exists so that a person — or another AI — can pick the project up by reading these files, then keep them current as the work moves.

> **Rule:** when a decision is made, an architecture changes, or a pitfall is solved — update the relevant doc in the same motion. A stale doc is worse than no doc.

## The project in one sentence

**Sovereign Kanban** replaces Nextcloud Deck with a Kanban board where **every card is a `.md` file** (YAML frontmatter) in Nextcloud Files — sovereign, portable, versionable data, readable in Obsidian.

## Map of documents

| Doc | Contents |
|-----|----------|
| [01-vision-and-philosophy.md](01-vision-and-philosophy.md) | The *why*. Sovereignty, the founding principle "the custom layer never owns the data", code philosophy. |
| [02-architecture.md](02-architecture.md) | The layers, the 3 apps, the `.board.yml`/`.md` file layout, the OCP boundary. |
| [03-phases-and-requirements.md](03-phases-and-requirements.md) | Phases 1/2/3, feature list (cards + boards), progress. |
| [04-environment-and-testing.md](04-environment-and-testing.md) | The 3 dev tiers (DDEV / staging-host / Tshinanu), how to run tests, how to deploy, the forge. |
| [05-nextcloud-pitfalls-solved.md](05-nextcloud-pitfalls-solved.md) | Nextcloud 33 pitfalls solved, with recipes. Read this **before** touching an NC app. |
| [06-decision-log.md](06-decision-log.md) | Dated log of structural decisions. |

## Quick start to resume the project

1. Read `01` and `02` for the *why* and the *how*.
2. Read `04` to stand up the environment and run the tests (`ddev exec vendor/bin/phpunit apps/`).
3. Check `03` for the next feature to build.
4. Keep `05` at hand whenever you touch Nextcloud code.

## Conventions

- Docs and code are in **English** (the lingua franca of developers).
- User-facing data and UI strings are in **French** (this is a Québec deployment — e.g. column names `En cours`, `Terminé`).
