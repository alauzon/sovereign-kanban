# Board sharing — technical spec (draft for review)

Status: **decisions locked 2026-07-08 — ready to implement (socle first, TDD).**
Feature: Phase 1 · "Partage de tableau entre usagers" (priority 1, with Steve).

> Prime directive unchanged: **the folder is the data.** Sharing a board must be
> sharing its folder — no separate ACL table the app owns. Delete the app and the
> share still exists in Nextcloud, like any shared folder.

## 1. Goal & scope

Let the owner of a board grant another person (or a group / NC Team) access to it,
read-only or collaborative, and optionally expose it through a public link.

In scope (Phase 1):
- Share a board with a **user**, a **group**, or a **Team (Circle)**.
- Two permission levels: **read-only** and **collaborate** (move/edit/create/delete cards).
- **Public link** (optional password + expiry).
- List and revoke shares from the board's "Éditer" panel.

Out of scope (later): per-column or per-card ACL, share notifications, share expiry UI polish.

## 2. Data model recap

A board is `Files/Kanban/{slug}/` — a `.board.yml` plus `NN-Column/` folders of
card dirs. Sharing a board == creating a Nextcloud share on that **folder node**.
The invitee reads/writes the same `.md` files; nothing is duplicated.

## 3. Why the stable slug (Wave 0) is a prerequisite

A Nextcloud share binds to a **node** (the folder). Our rename keeps the slug/folder
stable (`Board::withName` never moves the folder — enforced now by the create guard,
`BoardAlreadyExistsException`). So a share never breaks on rename. **If we ever made
the folder follow the name, every existing share and public link would break.** This
is the concrete reason Wave 0 chose slug-stability, and this spec depends on it.

## 4. Nextcloud API (to confirm against the deployed NC version — 33/34)

Use the server share manager, injected via DI — **not** `\OC::$server`:

- `OCP\Share\IManager` — `newShare()`, `createShare(IShare)`, `getSharesBy(...)`,
  `getShareById(...)`, `deleteShare(IShare)`.
- `OCP\Share\IShare` — `setNode()`, `setShareType()`, `setSharedWith()`,
  `setPermissions()`, `setPassword()`, `setExpirationDate()`.
- Share types (`OCP\Share\IShare`): `TYPE_USER`, `TYPE_GROUP`, `TYPE_LINK`,
  `TYPE_CIRCLE` (Teams).
- Permissions bitmask (`OCP\Constants`): `PERMISSION_READ`, `_UPDATE`, `_CREATE`,
  `_DELETE`, `_SHARE`.
- Resolve the board folder node via the user's `IRootFolder` userFolder:
  `getUserFolder($uid)->get('Kanban/'.$slug)`.

> Exact method signatures must be verified against the OCP docs of the target NC
> release before coding — do not assume. (Honesty: I have not pinned them to 33.0.5
> line-by-line yet.)

Permission presets:
- **read-only** = `PERMISSION_READ`.
- **collaborate** = `READ | UPDATE | CREATE | DELETE` (move a card = MOVE inside the
  folder = needs CREATE+DELETE on the tree). `PERMISSION_SHARE` stays **off** by
  default so invitees can't re-share.

## 5. REST endpoints to add (md-persistence)

```
GET    /api/v1/boards/{boardId}/shares            → list shares on the board
POST   /api/v1/boards/{boardId}/shares            → create (shareType, shareWith, level)
DELETE /api/v1/boards/{boardId}/shares/{shareId}  → revoke
POST   /api/v1/boards/{boardId}/shares/link       → create/return a public link
```

`level` ∈ {`read`, `collaborate`} maps to the presets above. Response objects expose
`{id, type, with, displayName, level, url?}` for the frontend list.

## 6. The hard problem — where does a shared board land for the invitee?

A received folder share mounts at the **root** of the invitee's Files by default
(e.g. `/{slug}`), **not** inside their `Kanban/`. Our board repository only scans
`Kanban/*/.board.yml`, so **the invitee's app would not list the shared board.**

Options:

- **A — mount under `Kanban/`.** On share (or on the invitee's first access), set the
  share mount point to `Kanban/{slug}`. Then the invitee's existing scan finds it with
  zero app changes. Risk: slug collision with a board the invitee already owns.
  Mitigation: mount as `Kanban/{slug}-partagé` (or `-{owner}`) on collision; the
  display name still comes from the shared `.board.yml`.
- **B — also scan received shares.** `FileBoardRepository::list()` additionally lists
  folder shares received by the user that contain a `.board.yml`. More code, more
  edge cases (a share can disappear mid-session), but no mount-point juggling.

**Recommendation: A.** It keeps `Kanban/` the single source the app scans and needs
almost no repository change. B leaks Nextcloud share semantics into the repository.

## 7. Public link

`TYPE_LINK` share on the folder. Default **read-only**. Offer optional password and
expiry. The link opens Nextcloud's public Files view of the folder (cards are plain
`.md` — readable). The anonymous *board view* (render the Kanban read-only for
anonymous users) is **now in Phase 1** (decision §10.4) — gated behind a security
pass (see §10.4).

## 8. Security checks (every endpoint)

- Caller must be the **owner** of the board folder (userFolder resolves it and the
  node owner == current uid) before creating/listing/revoking shares. Never trust the
  `boardId` alone.
- Validate `boardId` against `^[a-z0-9-]+$` (as elsewhere in `BoardController`).
- `shareWith` must resolve to a real user/group/team; reject otherwise (400).
- Public link: enforce the instance's link-share policy (password-required, max
  expiry) — read it from config, don't hardcode.
- Default deny on `PERMISSION_SHARE` (no re-sharing) unless explicitly requested.

## 9. Test plan (TDD — red first, per Alain's rule)

- **Unit (DDEV, no NC):** a thin `BoardShareService` wrapping `IManager` behind an
  interface; mock the manager. Assert: owner-check rejects a non-owner; `read`/
  `collaborate` map to the right permission bitmask; revoke calls `deleteShare` with
  the resolved share. Write these **before** the implementation.
- **Integration (staging NC):** real two-user share, invitee sees the board under
  `Kanban/`, moves a card, owner sees the move (same `.md`). Revoke → board gone for
  the invitee. Public link opens read-only.

## 10. Decisions (locked 2026-07-08 with Alain)

1. **Mount point** — Option A: mount the received share under the invitee's
   `Kanban/{slug}`. On slug collision, mount as `Kanban/{slug}-partagé` (then
   `-{owner}` if it still collides). Keeps `Kanban/` the single scan root.
2. **Default level** — offer both at invite time, default **read-only**.
3. **Teams (Circles)** — verified enabled on Tshinanu (`circles` 34.0.0). Ship
   **user + group + team** sharing together in Phase 1 (all three `TYPE_*`).
4. **Public** — Phase 1 ships **both** the read-only folder link **and** an
   anonymous read-only **Kanban board view** (token-based public route).
   ⚠️ Largest and most security-sensitive piece — gate behind a security pass
   (`/nadia-securite`): unguessable token, no board enumeration, rate-limiting,
   no metadata leak, honour the instance link-share policy (password/expiry),
   and never expose a write endpoint to anonymous callers.
5. **Re-sharing** — `PERMISSION_SHARE` stays **off** by default (invitees can't
   re-share).

## 11. Implementation plan (TDD)

- **Lot 1 — pure logic (unit, local) — DONE:** `SharePermissions` mapper
  (`read` → READ; `collaborate` → READ|UPDATE|CREATE|DELETE; SHARE never set).
  4 tests, red→green; no OCP dependency so it runs in the local suite.
- **Lot 2 — sharing to accounts (needs NC):** `BoardShareService` over
  `IManager` (create/list/revoke) for **user + group + team**, the owner-check
  guard, the `Kanban/{slug}` mount point (`-partagé` collision suffix), REST
  endpoints (§5), friendly errors. Behind an interface so the logic stays
  mockable.
- **Lot 3 — public exposure (needs NC + security pass):** the read-only folder
  link **and** the anonymous public board view. Security-review first (§10.4).
- **Lot 4 — validation (staging/Tshinanu):** two-account share (invitee sees the
  board under `Kanban/`, moves a card, owner sees it; revoke), plus an anonymous
  public-link test.
