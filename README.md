# Sovereign Kanban: MD Persistence

Backend API for Sovereign Kanban, a file-based Kanban system for Nextcloud.

Stores all data as `.md` files in Nextcloud Files for complete data sovereignty.

## Phase 1
- REST API endpoints (CRUD)
- File-based storage (`.md` frontmatter + attachments)
- Integration with Nextcloud

## Setup
```bash
cd apps/sovereign-kanban-md-persistence
composer install
vendor/bin/phpunit tests/
```
