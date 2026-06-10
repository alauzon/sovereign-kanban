# Environment & testing

## Three-tier setup (Kate's test pyramid mapped onto infrastructure)

| Tier | Where | What | Speed |
|------|-------|------|-------|
| **Unit** (most) | **DDEV** on your workstation | pure logic (Card, Board, repositories) — no NC needed | instant (~60 ms) |
| **Integration / e2e** (few) | a **staging LXC/VM** running a disposable Nextcloud | real NC 33, Playwright, WebDAV MOVE, ACL sharing | LAN-fast |
| **Final validation** | your **production Nextcloud** | the real environment, community use | — |

**Rule (one tool per job):** we do NOT run Nextcloud inside DDEV. DDEV is for unit tests + tooling; the staging LXC is the real NC.

## Running the unit tests

The DDEV project lives at the repo root (`.ddev/`). The web container has PHP 8.2 + PHPUnit 10.

```bash
cd ~/dev/sovereign-kanban-md-persistence
ddev exec "cd /var/www/html && vendor/bin/phpunit apps/sovereign-kanban-md-persistence/tests/"
```

Unit tests need no Nextcloud: the domain (`Card`, `Board`) and the file repositories talk only to the filesystem (temp dirs in `setUp`/`tearDown`).

## TDD discipline (RED → GREEN)

1. **RED** — write the failing test first. Run it; it MUST fail (class missing, wrong value). This proves the test tests something real.
2. **GREEN** — implement the minimum to pass.
3. Re-run the full suite; keep it green.

Never skip RED. A test that passes the moment you write it is suspect.

## The staging NC

- Stack mirrors production: **PHP 8.3 / MariaDB 10.11 / Redis / nginx** (official NC nginx config), NC **33.0.5**.
- Reach it from your workstation at the staging host's LAN address.
- Admin + DB credentials live in your secrets vault. Disposable, no real data.
- Host access: SSH to the staging host, then run `occ` inside the container.

## Deploying to staging

The repo is the source of truth. Deploy by cloning/pulling on the staging CT and enabling the apps via `occ`. After changing an app's `routes.php`, **bump `<version>` in `info.xml`** then `occ app:disable && occ app:enable` to drop Nextcloud's stale route cache (see [05-nextcloud-pitfalls-solved.md](05-nextcloud-pitfalls-solved.md)).

## Git & forge

- **Canonical working remote: a self-hosted Forgejo.** `main` tracks `forgejo/main`; `git push` goes there.
- **Public mirror: GitHub** — a discoverability mirror, pushed at public launch.
- Any API token for repo/CI automation lives in your secrets vault, never in the repo.

## CI (planned, not yet built)

When the e2e Playwright suite exists and is worth automating, stand up a **Forgejo Actions runner** next to the staging NC. Until then, a `composer test` script + a `pre-push` git hook running the unit suite is enough. Don't build CI before there are e2e tests to run.
