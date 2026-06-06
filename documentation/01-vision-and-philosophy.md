# Vision & philosophy

## Why this project exists

Nextcloud Deck stores cards in an opaque database. If Deck goes away, changes, or corrupts, the data is trapped. **Sovereign Kanban** inverts this: data lives as flat Markdown files in Nextcloud Files — owned by the user, synced, backed up, readable in any editor (Obsidian included), versionable with git.

This is a **Serveurs du Peuple** project: digital sovereignty is not a slogan, it's the architecture. The name says it — *Sovereign* Kanban.

## The founding principle

> **The custom layer never owns the data — it decorates flat `.md` files that Nextcloud Files edits natively.**
> *(Bruno, round table 2026-05-31)*

Concrete consequences:
- The Nextcloud app stores **nothing** in a proprietary SQL table. It reads and writes files.
- Moving a card = **moving a file** (WebDAV MOVE). Atomic, git-able, diffable.
- Uninstalling the app destroys no data — the `.md` files remain in Files.
- A card's body is editable by Nextcloud Text (collaborative), by Obsidian, by `vim`. It doesn't matter.

## Data format

- **Card**: a directory `{uuid}-{slug}/` containing `card.md` (YAML frontmatter + Markdown body). Optionally `comments.md`, `history.md`, `attachments/`.
- **Board**: a directory containing `.board.yml` (name, color, columns) + one subdirectory per column (`01-Backlog/`, `02-En cours/`…).
- **Column**: a directory. The `NN-` prefix sets the order.

A card's UUID is **stable**: it survives moves and renames. A board's slug is **stable** too: renaming a board does not move its folder (we only rewrite `.board.yml`).

## Code philosophy

- **Immutable value objects** (`Card`, `Board`): no setters; to "change", return a new instance (`withName()`, `withColor()`). State never mutates under your feet.
- **Pure filesystem repositories** (`FileCardRepository`, `FileBoardRepository`): they talk to the disk, not to Nextcloud. So they are **testable in isolation, without NC** — hence 60 ms unit tests.
- **Thin OCP boundary**: Nextcloud classes (`OCP\…`, Controllers) only touch the entry layer. Business logic is independent of them. Everything can be tested before a Nextcloud is even started.
- **Strict TDD, RED → GREEN**: write the failing test first (RED — it must prove it tests something real), then implement (GREEN). See [04-environment-and-testing.md](04-environment-and-testing.md).
- **Plain, portable Markdown**: no Obsidian-specific syntax forced into the data. We target CommonMark portability.

## What we deliberately do NOT do

- No proprietary SQL table for cards.
- No behavioral surveillance, no silent telemetry.
- No dependency on an external service to function.
