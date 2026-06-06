# Phases & requirements

Features prioritized with Steve Lauzier (a Phase 1/2/3 feature table to replace Deck). Checked boxes = done.

## Phase 1 — Kanban MVP (replaces daily Deck use for SdP circles)

### Cards

- [ ] Create a card → new `.md` in the column
- [ ] Edit a card: body via Nextcloud Text (collaborative) + frontmatter via side panel
- [ ] Move (drag-drop) → WebDAV MOVE between column folders
- [ ] Delete
- [ ] Archive → `Archivé` column (reversible)
- [ ] Fields: assignees, colored labels, start/due dates, subtasks (checklists), priority, status

### Boards *(added by Alain on 2026-06-06)*

- [x] **Create a board** *(logic: `FileBoardRepository::create`)*
- [x] **List boards** *(`list`)*
- [x] **Edit name + color** *(`find` + `withName`/`withColor` + `save`; stable slug)*
- [x] **Switch board** *(data: `list` + `find`; selection on the frontend)*
- [ ] **Share a board** (Nextcloud ACL on the folder) — requires a real NC
- [ ] **Edit a board's sharing**

### Views & collaboration

- [ ] Calendar/agenda view (alternative to the board, cards with due dates)
- [ ] History: a `## History` section in each `.md`
- [ ] Export/Import JSON (serialize the folder structure)
- [ ] Attachments: links to NC files in the `.md` body
- [ ] Add a card from Talk (NC Talk bot → creates the file)

### Phase 1 tests (see [04-environment-and-testing.md](04-environment-and-testing.md))

- Suite A — file contract (PHPUnit): create/move/delete → correct disk state, stable UUID. *(in progress, 28 tests green)*
- Suite B — drag-drop concurrency (Playwright multi-context): 2 clients move the same card → one column only, file neither duplicated nor lost.
- Suite C — Nextcloud Text round-trip: edit the body → reload → frontmatter intact (NC Text has already corrupted wikilinks; real risk).

## Phase 2 — Advanced Kanban

Public REST API · multi-criteria filters + board overlays · comments with notifications · add to calendar (CalDAV) · Talk conversation from a card · group boards by category · sort boards by drag-drop · multiple `.md` per card (tabs) · sub-cards · grid/timeline/graph views · Microsoft-style status · checklists independent of the description · extract a checklist item → new card.

## Phase 3 — Full project-management platform

Recurring tasks · two-way calendar view (card ↔ CalDAV event) · NC form → card · Nextcloud Recipes integration · Nextcloud Office presentation from a card · mobile PWA (Android/F-Droid first) · customizable panels (Mattermost-style) · **AI agents** creating/moving cards (SdP Phase III agentic stack).

## Invariants to guarantee with tests (Kate)

- **A** — the parent folder is the canonical column. A card is never orphaned nor duplicated across two columns.
- **B** — the YAML frontmatter survives a body edit via NC Text.
- **C** — a card's UUID is unique and stable; creating N cards simultaneously → zero collision; moving → UUID unchanged.
