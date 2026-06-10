# Decision log

Dated, structural decisions. Newest first. When you make a decision that shapes the project, add an entry.

## 2026-06-06

**Documentation lives in the repo, in English.** This `documentation/` folder is the project's living brain so any human or AI can resume the work. English for docs and code (developer lingua franca); French only for user-facing data/UI (Québec deployment). — *Alain*

**Forge: self-hosted Forgejo as the working remote; GitHub as the public mirror.** "Open Source" does not mean "GitHub". For a project literally named *Sovereign* Kanban, sovereign hosting is part of the credibility. The working remote is a self-hosted Forgejo; GitHub is a discoverability mirror, pushed at public launch. — *Alain*

**Dev environment: fresh local NC, not a clone of a real site.** Cloning a live site carries real members' data → privacy/Loi 25 concern on a laptop. A fresh NC is smaller, zero real data, zero privacy issue — and the Kanban app doesn't need real data (we generate test `.md`). Three tiers: DDEV (unit) / staging LXC (e2e) / production (final). — *Frank's recommendation, Alain approved*

**Stop developing on production.** Developing on the live community server is dangerous (it was taken down twice during bring-up) and too slow for TDD's tight RED→GREEN loop (SSH round-trips). Build locally; deploy to staging; validate on production last. — *Frank*

**Staging NC stood up on a disposable LXC, NC 33.0.5**, prod-fidelity stack (PHP 8.3 / MariaDB / Redis / nginx official config). Disposable, LAN-only.

**Board-level features added to Phase 1 scope:** create / rename / recolor / list / share / switch boards (on top of the card-level features). A board = a folder + `.board.yml`; sharing = NC ACL on the folder.

## 2026-05-31

**Founding principle (round table):** the custom layer never owns the data — it decorates flat `.md` files that Nextcloud Files edits natively. All Kanban data is `.md` + YAML frontmatter in NC Files; moving a card = moving a file. — *Bruno*
