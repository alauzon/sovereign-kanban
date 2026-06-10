# Sovereign Kanban

A file-based Kanban board for Nextcloud where **every card is a plain `.md` file**
(YAML frontmatter + Markdown body) living in Nextcloud Files. No proprietary
table ever owns your data: boards and cards are portable, versionable, and
readable without the app.

A sovereign replacement for Deck.

## Why

The custom layer never owns the data. A board is a folder + `.board.yml`; a card
is a `.md` file. Delete the app and your boards are still there — plain Markdown
in `Files/Kanban/`, openable in any editor, syncable, git-friendly.

## The apps

This repository is a monorepo of three Nextcloud apps:

| App | Role |
|-----|------|
| `sovereign-kanban` | The board UI (page, navigation entry, JS/CSS). |
| `sovereign-kanban-md-persistence` | The backend: reads/writes boards and cards as `.md` + `.board.yml`, exposes a REST API. |
| `sovereign-kanban-import` | One-shot migration from the Deck app. |

## Install

See [documentation/07-installation-playbook.md](documentation/07-installation-playbook.md)
for the full deploy recipe. In short: drop the apps into your Nextcloud
`apps/` directory and enable them with `occ`.

## Develop

Unit tests run in DDEV, no Nextcloud needed:

```bash
ddev exec "cd /var/www/html && vendor/bin/phpunit apps/sovereign-kanban-md-persistence/tests/"
```

The full developer documentation lives in [documentation/](documentation/) —
vision, architecture, the three dev tiers, Nextcloud pitfalls, and the decision
log.

## Testing

This project is tested with BrowserStack.

## License

Copyright (C) 2026 Alain Lauzon and Serveurs du Peuple.

Licensed under the [GNU AGPL-3.0-or-later](LICENSE) — the same license as
Nextcloud itself. Any modified version run as a network service must make its
source available to its users.
