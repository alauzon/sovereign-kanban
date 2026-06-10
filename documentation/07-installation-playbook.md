# Installation Playbook

How to install Sovereign Kanban on a Nextcloud instance. Tested on a disposable
staging NC and a production NC. The model assumes an LXC container (or VM)
running a **direct** Nextcloud install (not AIO/Docker) behind
**nginx + PHP-FPM**, with shell access to run `occ` inside the container.

## Apps

| App | Role | Has `vendor/` |
|-----|------|---------------|
| `sovereign-kanban` | Frontend / UI (page, nav entry, JS, CSS) | no |
| `sovereign-kanban-md-persistence` | Backend (domain, `.md` persistence, REST API) | **yes** (commonmark, symfony/yaml, ramsey/uuid) |
| `sovereign-kanban-import` | *Optional* Deck → SK migration (one-shot, removable) | yes |

The two first apps are required. The import app is only needed to migrate
existing Deck boards.

## 0. Pre-flight (read-only — run any time, daytime is fine)

On the target container:

```bash
cd /var/www/nextcloud
# NC version must be >= 33
sudo -u www-data php8.3 occ status | grep -iE 'version|maintenance'
# nginx MUST serve .mjs as JavaScript, otherwise the Text editor AND several
# core NC modules (user_status, notifications, theming) break silently.
grep -i javascript /etc/nginx/mime.types          # expect: application/javascript  js mjs;
# The Text app must be enabled (the card description / comments editor).
sudo -u www-data php8.3 occ app:list | grep -E 'text:'
# Note the ZFS subvol (for the snapshot).
zfs list | grep "subvol-<CTID>"
```

**If `.mjs` is missing** from `mime.types`:

```bash
sed -i 's|application/javascript                js;|application/javascript                js mjs;|' /etc/nginx/mime.types
nginx -t && systemctl restart nginx     # RESTART, not reload — reload does not re-read mime.types
```

Decision gate: NC ≥ 33 ✅, nginx serves `.mjs` ✅, Text enabled ✅. If any fails,
fix it (daytime) before the install window.

## 1. Build the release (on a machine WITH composer)

The target host does **not** need composer — we ship the built `vendor/`.

```bash
deploy/build-release.sh                 # → /tmp/sovereign-kanban-release.tar.gz
```

## 2. Snapshot the container (ALWAYS, right before installing)

```bash
# On the Proxmox host:
zfs snapshot rpool/data/subvol-<CTID>-disk-0@pre-skanban-$(date +%Y%m%d-%H%M)
```

Keep the snapshot until the install is validated (24–48 h), then destroy it.

## 3. Deploy

```bash
# Copy the tarball to the Proxmox host, then into the container:
scp /tmp/sovereign-kanban-release.tar.gz root@<host>:/tmp/
pct push <CTID> /tmp/sovereign-kanban-release.tar.gz /tmp/skanban.tar.gz

pct exec <CTID> -- bash -c '
  cd /var/www/nextcloud/apps
  rm -rf sovereign-kanban sovereign-kanban-md-persistence
  tar -xzf /tmp/skanban.tar.gz
  chown -R www-data:www-data sovereign-kanban sovereign-kanban-md-persistence
  cd /var/www/nextcloud
  sudo -u www-data php8.3 occ app:enable sovereign-kanban-md-persistence
  sudo -u www-data php8.3 occ app:enable sovereign-kanban
  # A version bump can flip the instance into "upgrade required"; settle it:
  sudo -u www-data php8.3 occ upgrade || true
  sudo -u www-data php8.3 occ maintenance:mode --off || true
  # Frontend assets are cached by a theming cachebuster — bump it every deploy:
  sudo -u www-data php8.3 occ config:app:set theming cachebuster --value="$(date +%s)"
  redis-cli FLUSHALL >/dev/null 2>&1 || true
  systemctl restart php8.3-fpm
'
```

Notes:
- **Bump the cachebuster on every frontend deploy**, or the browser serves
  stale JS/CSS (curl is unaffected, which masks the problem).
- `occ upgrade` + `maintenance:mode --off` clear the transient "upgrade
  required" state a version bump can trigger.

## 4. Smoke test

```bash
pct exec <CTID> -- bash -c 'cd /var/www/nextcloud && sudo -u www-data php8.3 occ app:list | grep sovereign'
# expect both apps enabled, versions matching the release
```

In a browser, logged into the target NC:
1. Open `/apps/sovereign-kanban/` — the board renders on a white sheet.
2. Create a board → a column → a card.
3. Open the card — the **Text editor** mounts for the description (toolbar).
4. Add a comment (renders rich), edit it on click, delete it.
5. `📋 Carte depuis un gabarit` → a card is created from a template.
6. In `Files → Kanban/`, confirm `Modèles/` + `Procédures/` (`.md` files) and
   the board folders/cards exist and open in the Files editor.

Console (F12) should be clean — no `.mjs` MIME errors, no `[SK]` errors.

## 5. Rollback

If something is wrong and cannot be fixed forward:

```bash
# Cleanest, if no real user data changed since the snapshot:
zfs rollback rpool/data/subvol-<CTID>-disk-0@pre-skanban-<stamp>   # ⚠️ discards ALL changes since the snapshot
# Or, less invasive — just remove the apps:
pct exec <CTID> -- bash -c 'cd /var/www/nextcloud && sudo -u www-data php8.3 occ app:disable sovereign-kanban && sudo -u www-data php8.3 occ app:disable sovereign-kanban-md-persistence'
```

The Kanban `.md` files in `Files/Kanban/` are plain Markdown and survive an app
removal — the data is never owned by the app.

## Environment specifics

Adapt to your own hosts. A typical layout:

| Instance | PHP | Deploy window |
|----------|-----|---------------|
| Staging (disposable) | php8.3 | any |
| Production (single user) | php8.3 | any |
| Production (multi-user) | php8.3 | a low-traffic maintenance window |
