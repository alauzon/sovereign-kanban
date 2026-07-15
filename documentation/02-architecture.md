# Architecture

## The three Nextcloud apps

| App | Role | Namespace |
|-----|------|-----------|
| `sovereign-kanban` | **Frontend / UI** — renders the board, Nextcloud page, nav entry | `OCA\SovereignKanban` |
| `sovereign-kanban-md-persistence` | **Backend** — domain + `.md` persistence, REST API | `OCA\SovereignKanbanMdPersistence` |
| `sovereign-kanban-import` | **Migration** Deck → Sovereign Kanban (one-shot, removable after) | `OCA\SovereignKanbanImport` |

The repo is a monorepo: `apps/<app>/` for each.

## Layers (core outward)

```
┌─────────────────────────────────────────────────────────┐
│  Frontend (sovereign-kanban)                             │
│  templates/main.php + css/ + js/  — the board           │
├─────────────────────────────────────────────────────────┤
│  Nextcloud boundary (OCP)                                │
│  Controllers (DataResponse / TemplateResponse)          │
│  ← the ONLY place that depends on OCP\…                  │
├─────────────────────────────────────────────────────────┤
│  Persistence (pure filesystem — testable without NC)     │
│  FileCardRepository, FileBoardRepository                  │
├─────────────────────────────────────────────────────────┤
│  Domain (immutable value objects)                        │
│  Card, Board                                             │
└─────────────────────────────────────────────────────────┘
```

**Why this split:** the business logic (domain + persistence) knows nothing about Nextcloud. We test all of it in PHPUnit, locally, without starting an NC. Nextcloud only enters at the boundary (Controllers), tested via e2e (Playwright against a real NC).

## Storage abstraction (`lib/Storage/`)

The repositories never touch the filesystem directly. They depend on a `Storage` interface (relative paths: `exists`, `read`, `write`, `makeDir`, `delete`, `move`, `childDirectories`, `scoped`). Two implementations:

- **`LocalStorage`** — raw filesystem, rooted at a base dir. Used by **unit tests** (fast, no Nextcloud).
- **`NextcloudStorage`** — the Nextcloud Files API (`IRootFolder`/`Folder`), rooted at a Folder node. Used in **production**.

Why it matters: writing through the Files API keeps NC's file cache in sync, so the **Files app and desktop sync client see Kanban changes immediately** — and reading reflects external edits. **Bidirectional sync, verified.** It also works on object storage (S3). Controllers inject `IRootFolder` and build a `NextcloudStorage` rooted at the user's `Files/Kanban/` (boards) or a board folder (cards).

## Existing classes (`sovereign-kanban-md-persistence/lib/Kanban/`)

- **`Card`** — immutable value object: `id` (stable uuid), `title`, `column`, `description`, `created_at`, `assignees`, `due_date`, `start_date`, `procedures`, `priority`, `tags`, `phase`. `create()`, `withColumn()`, `fromMarkdown()`, `toYAMLFrontmatter()`, `toArray()`. Unknown frontmatter keys are kept in `$extra` and written back untouched. **The frontmatter keys are specified in [10-card-md-format.md](10-card-md-format.md)**, whose conformance suite is the format's definition.
- **`FileCardRepository`** — `save`, `moveCard`, `delete`. Layout: `{baseDir}/{column}/{uuid}-{slug}/card.md`.
- **`Board`** — immutable value object: `id` (stable slug), `name`, `color`, `columns`, `created_at`. `create()` (slugifies the name), `withName()`, `withColor()`, `toYaml()`.
- **`FileBoardRepository`** — `create`, `list`, `find`, `save`, `delete`. Layout: `{rootDir}/{board-slug}/.board.yml` + `NN-Name/` column folders.
- **`KanbanController`** — orchestration (currently plain PHP; will become a real NC Controller at the OCP boundary).

## File layout (on disk, inside NC Files)

```
Files/Kanban/
  projets-sdp/                 ← board (stable slug)
    .board.yml                 ← name, color, columns, created_at
    01-Backlog/
      a1b2c3d4-...-configurer-mail/
        card.md                ← YAML frontmatter + Markdown body
    02-En cours/
    03-Terminé/
    04-Archivé/
```

- **Move a card** = `mv` the `{uuid}-{slug}/` directory between columns (WebDAV MOVE). UUID preserved.
- **Rename a board** = rewrite `.board.yml`; the folder (slug) does not move.
- **Archive** = move to the `Archivé` column (reversible).

## Frontend & sync

- Board in Vue 3 (target); for now a static PHP template + CSS using Nextcloud CSS variables.
- **Drag-drop** = WebDAV MOVE with *optimistic update* (move it visually immediately, MOVE in the background, roll back on error).
- **Multi-user sync**: 5 s polling in Phase 1; `notify_push` (NC WebSocket) in Phase 2.

## Sharing

Reuses **Nextcloud ACLs** on the board's folder (public link, read/write). No homegrown permission system. → requires a real NC, so tested on staging (see [04-environment-and-testing.md](04-environment-and-testing.md)).
