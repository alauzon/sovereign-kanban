# Testing playbook — unit & functional

Concrete, copy-pasteable procedures for the two test tiers. Background and the
test pyramid live in [04-environment-and-testing.md](04-environment-and-testing.md);
the deploy recipe lives in [07-installation-playbook.md](07-installation-playbook.md).

**The golden rule (from SdP ops):** a fix for a user-visible bug ships with a
*reproduce-then-fix* test. Run it and watch it **FAIL** on the current code
(it reproduces the bug), then fix, then watch it **PASS**. A test that is green
the moment you write it proves nothing.

## Test map

| Tier | Covers | Needs a real NC? | Runs where | Command |
|------|--------|------------------|------------|---------|
| **Unit** (most) | Pure logic: `Card`, `Board`, file repositories, sharing **policy** (`SharePermissions`, `BoardShareService` over a fake gateway) | No | Local PHP or DDEV | `vendor/bin/phpunit …/tests/Unit/` |
| **Functional / e2e** (few) | The **controllers** + **real Nextcloud shares** across two accounts — the read-only ACL, received-board resolution | Yes | Inside a staging container (Tshinanu, CT 211) | `runuser -u www-data -- php /tmp/<test>.php` |

Why the split: the controllers, `ReceivedBoardLocator` and the share gateway
depend on **OCP**, which is only loadable inside a booted Nextcloud. Their logic
is therefore *pushed down* into pure classes that the unit suite tests with
fakes, and what is left at the OCP boundary is proven by a functional test.

## Unit tests

Pure PHP, no Nextcloud — the domain and repositories talk only to temp dirs.

```bash
cd ~/dev/sovereign-kanban-md-persistence

# Whole suite:
vendor/bin/phpunit apps/sovereign-kanban-md-persistence/tests/

# One area (fast loop while working on sharing):
vendor/bin/phpunit apps/sovereign-kanban-md-persistence/tests/Unit/Sharing/
```

**Local caveat — the `yaml` PHP extension.** Three `Kanban/` tests call the
native `yaml_parse()`. If your local PHP lacks `ext-yaml` they ERROR with
`Call to undefined function yaml_parse()` — an environment gap, **not** a
failure. Run the full suite in **DDEV** (it has the extension) for a clean
green, or scope to the area you changed:

```bash
ddev exec "cd /var/www/html && vendor/bin/phpunit apps/sovereign-kanban-md-persistence/tests/"
```

RED → GREEN discipline: see [04](04-environment-and-testing.md#tdd-discipline-red--green).

## Functional (e2e) tests

These boot a real Nextcloud, act as two accounts, drive the **actual
controllers** against **real shares**, and assert on the `DataResponse` status
and body. They are the only place the controller-level ACL (e.g. a 403 on a
read-only share) can be proven.

### Prerequisites

- Two accounts on the staging instance (**Tshinanu, serveur3 CT 211**):
  `Test 1` (owner) and `Test 2` (recipient). Already provisioned.
- The app **deployed and enabled** on that container (see [07](07-installation-playbook.md)).

### Catalog

| Script | Proves |
|--------|--------|
| `tests/Functional/readonly_enforcement.php` | A read-only recipient can **read** but every **write** (card create/update/move/delete, comment, board addColumn) returns **403 `read_only`**; a collaborate recipient **can** write; refused writes leave data untouched; the owner is unaffected. Guards the read-only bypass fix (2026-07-12). |

### Running one

The release tarball **excludes `tests/`**, so the file is not on the container
after a deploy — push it separately, then run it as the web user:

```bash
# From your workstation (via the Proxmox host):
scp apps/sovereign-kanban-md-persistence/tests/Functional/readonly_enforcement.php \
    serveur3:/tmp/readonly_enforcement.php
ssh serveur3 'pct push 211 /tmp/readonly_enforcement.php /tmp/readonly_enforcement.php'
ssh serveur3 'pct exec 211 -- runuser -u www-data -- php /tmp/readonly_enforcement.php'
# Exit 0 = all assertions passed; 1 = at least one failed.

# Tidy up:
ssh serveur3 'pct exec 211 -- rm -f /tmp/readonly_enforcement.php'
```

Each script is **idempotent and self-cleaning**: it creates throwaway boards
prefixed `zzz-e2e-`, shares them between the two accounts, asserts, then deletes
them (and defensively deletes leftovers on the next run). It touches no real
board.

### Writing a new functional test

Follow the pattern in `readonly_enforcement.php`:

1. `require_once '/var/www/nextcloud/lib/base.php';` to boot NC, then pull
   services from `\OC::$server`.
2. Wire the classes under test **by hand** (their constructors are simple) —
   don't fight the DI container from a CLI script.
3. `actAs($uid)` = `IUserSession::setUser` + `\OC_User::setUserId` +
   `\OC_Util::tearDownFS()/setupFS($uid)`, so received shares mount for the
   acting user.
4. Assert on `DataResponse::getStatus()` and `getData()`, count pass/fail, and
   `exit($fail === 0 ? 0 : 1)`.
5. Add an **integrity** assertion (state unchanged after a refused write) that
   is representation-agnostic: capture the value before, compare after — never
   hard-code a derived string (column names carry an `NN-` folder prefix).

## Reproduce-then-fix protocol (before every deploy)

For a bug fix, in order:

1. **Unit** — add the failing case first. Example: the read-only fix added
   `testReceivedBoardsUnionsPermissionsOfDuplicateShares` (asserted `1 !== 15`
   on the old dedup). It must FAIL, then PASS after the fix.
2. **Functional** — if the bug lives at the OCP boundary (controllers, shares),
   run the matching functional test on staging **before** deploying the fix
   (reproduces), then **after** (confirms). The unit suite alone cannot see a
   controller returning 403.
3. Keep the whole unit suite green.

## Deploy-time checklist

1. Unit suite green (DDEV).
2. Build the release, **ZFS snapshot** the container, deploy — [07](07-installation-playbook.md).
   Remember the php-fpm service differs per container (204 → `php8.3-fpm`,
   211 → `php8.1-fpm`).
3. Push and run the relevant **functional** test on the container — it must be
   fully green against the just-deployed code.
4. Smoke test in a browser, logged in as a normal user.
5. Destroy the pre-deploy snapshot once validated (24–48 h).
