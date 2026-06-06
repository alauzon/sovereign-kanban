# Decision log

Dated, structural decisions. Newest first. When you make a decision that shapes the project, add an entry.

## 2026-06-06

**Documentation lives in the repo, in English.** This `documentation/` folder is the project's living brain so any human or AI can resume the work. English for docs and code (developer lingua franca); French only for user-facing data/UI (Québec deployment). — *Alain*

**Forge: Codeberg canonical (eventual) + GitHub mirror; Forgejo as the working remote now.** "Open Source" does not mean "GitHub". For a project literally named *Sovereign* Kanban, sovereign hosting is part of the credibility. Working remote today is the self-hosted Forgejo (`forgejo.example`); public canonical will be Codeberg (non-profit, EU, discoverable); GitHub stays as a dormant discoverability mirror, pushed only at public launch. — *Alain*

**Dev environment: fresh local NC, not a clone of a real site.** A clone of `a-real-community-site` would be smaller on disk but carries real members' data → Loi 25 concern on a laptop. A fresh NC is smaller still, zero real data, zero privacy issue — and the Kanban app doesn't need real data (we generate test `.md`). Three tiers: DDEV (unit) / staging-host LXC (e2e) / Tshinanu (prod). — *Frank's recommendation, Alain approved*

**Stop developing on production (Tshinanu).** Developing on the live community server is dangerous (it was taken down twice during bring-up) and too slow for TDD's tight RED→GREEN loop (SSH round-trips). Build locally; deploy to staging; validate on Tshinanu last. — *Frank*

**Staging NC stood up on staging-host (the staging container, `STAGING_IP`), NC 33.0.5**, prod-fidelity stack (PHP 8.3 / MariaDB / Redis / nginx official config). Disposable, LAN-only, full autonomy on staging-host.

**Board-level features added to Phase 1 scope:** create / rename / recolor / list / share / switch boards (on top of the card-level features). A board = a folder + `.board.yml`; sharing = NC ACL on the folder.

## 2026-05-31

**Founding principle (round table):** the custom layer never owns the data — it decorates flat `.md` files that Nextcloud Files edits natively. All Kanban data is `.md` + YAML frontmatter in NC Files; moving a card = moving a file. — *Bruno*
