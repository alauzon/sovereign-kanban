# Nextcloud 33 pitfalls solved

Hard-won during bring-up on Tshinanu (2026-06-06). Read this before touching any Nextcloud app — it will save you hours.

## The four app pitfalls (a board page would not load)

### 1. `info.xml` namespace must NOT carry the `OCA\` prefix

Nextcloud prepends `OCA\` automatically. Writing `<namespace>OCA\SovereignKanban</namespace>` yields `OCA\OCA\SovereignKanban\…` → `ReflectionException: class does not exist`.

**Fix:** `<namespace>SovereignKanban</namespace>`.

### 2. A page controller method needs `#[NoCSRFRequired]` + `#[NoAdminRequired]`

A browser navigating to `/apps/<app>/` sends a GET with a session cookie but **no request token**. Without the attribute, Nextcloud rejects it → *"Access non autorisé / CSRF check failed"*.

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

#[NoAdminRequired]
#[NoCSRFRequired]
public function index(): TemplateResponse { ... }
```

`#[NoAdminRequired]` = any logged-in user (not just admin). `#[PublicPage]` = no login at all.

### 3. A `<navigations>` entry requires an `img/app.svg`

Declaring a nav entry without providing `img/app.svg` → `RuntimeException: image not found: default-app-icon` in `NavigationManager`, which builds the menu on **every page** → **HTTP 500 across the whole instance**, not just your app.

**Fix:** ship an `img/app.svg` before declaring `<navigations>`.

### 4. Stale route cache → silent 404 (no log, no exception)

Nextcloud caches compiled routes **per app version**. If `routes.php` was broken then fixed without changing the version, the old (empty/broken) cache persists and the route 404s with **nothing in `nextcloud.log`**. (Note: there is no `occ routes:list` in standard NC — don't rely on it to diagnose.)

**Fix:** bump `<version>` in `info.xml`, then `occ app:disable <app> && occ app:enable <app> && occ cache:clear-all`.

## NEVER `rm -rf` `appdata_*` on a running Nextcloud

`data/appdata_<instanceid>/` is not just throwaway cache. It holds `suspicious_login` ML models (loaded on every login), `richdocuments`/Collabora capability cache (loaded during template layout), and the core JS/CSS combined cache. Deleting it makes those apps' listeners throw → **HTTP 500 on every authenticated page**, instance-wide. The symptoms look nothing like the app you were debugging.

**Recovery that worked:** restore the deleted files from a ZFS snapshot taken **before** the deletion — not a full rollback. Hourly auto-snapshots (`zfs-auto-snap_hourly-*`) are exposed under `.zfs/snapshot/`. From the Proxmox host: `cp -a /rpool/data/subvol-<CTID>-disk-0/.zfs/snapshot/<snap>/var/www/nextcloud/data/appdata_<id> <live>/` (preserves the mapped uid). Move the broken one aside first, then `occ cache:clear-all`, then verify `apps/files/`, `apps/dashboard/`, `settings/user` return 200.

## Testing app endpoints with curl

Basic Auth alone hits the CSRF/auth wall. Add the OCS header to bypass it for API access:

```bash
curl -u "User:pass" -H "OCS-APIRequest: true" https://host/apps/<app>/ -k
```

A browser (session cookie) doesn't need this header — but a controller method still needs pitfall #2's attributes for browser GETs.

## nginx: use the official Nextcloud config

Hand-rolling the nginx vhost caused hours of 301/404/static-PHP grief on Tshinanu. The **official Nextcloud nginx configuration** handles `/apps/`, `index.php`, `remote.php`, and the `.php` fastcgi block correctly. Start from it; only change `root`, `server_name`, and the `fastcgi_pass` socket (`unix:/run/php/php8.3-fpm.sock`).
