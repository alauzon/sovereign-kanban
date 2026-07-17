# Browser (e2e) tests — the tier the other two cannot reach

Playwright driving a **real browser** against **Tshinanu** (`cloud.tshinanu.org`,
serveur3 CT 211). Third tier of the test map:

| Tier | Reaches | Blind to |
|---|---|---|
| Unit (120) | pure PHP logic | everything OCP and above |
| Functional (6 scripts, on CT 211) | the real controllers + files | **the JavaScript in the browser** |
| **Browser (this)** | the whole chain the user actually touches | nothing below it |

Why it exists: on 2026-07-15 the due-date fix went green at both lower tiers
while the browser stored `00:00` — the bug's last leg was in `main.js`, which no
tier exercised. Alain then asked the right one-word question about the manual
check that patched over the hole: *« Test fonctionnel ? »*. This tier is the
answer, and it is also the substrate for the **characterization test** (Kate's
gate): the Vue migration rewrites the UI, so only a test that drives the UI can
stay meaningful across it.

## One-time setup — the credential deposit (a human does this, not Claude)

The tests log into Tshinanu as the test account. Per the secrets rule, the
password must never transit through a model or a transcript: **you** deposit it,
the code only reads it.

```bash
mkdir -p ~/.config/sk-e2e
cat > ~/.config/sk-e2e/env <<'EOF'
SK_E2E_BASE_URL=https://cloud.tshinanu.org
SK_E2E_USER=Test 1
SK_E2E_PASS=<mot de passe du compte Test 1>
EOF
chmod 600 ~/.config/sk-e2e/env
```

An app password minted from Test 1's security settings also works if the login
form accepts it; the account password is fine — it is a test account.

## Running

```bash
cd tests/Browser
npx playwright test                 # headless
npx playwright test --headed       # watch it
```

Caveats:

- Nextcloud brute-force protection throttles repeated failed logins from one IP.
  The config uses a shared `storageState` so the suite logs in **once**.
- Tests create boards prefixed `zzz-e2e-nav` and delete them through the UI in
  teardown (accepting the `window.confirm`). If a run is killed hard, delete the
  leftover board by hand — it is under the test account only.
- Same safety rule as the functional tier: Tshinanu hosts real community
  members. Tests act only as the test accounts, only on `zzz-e2e-*` boards.
