#!/usr/bin/env bash
#
# Build a deployable release of Sovereign Kanban.
#
# Bundles the two runtime apps WITH their vendor dependencies (commonmark,
# symfony/yaml, ramsey/uuid) so the target Nextcloud host does NOT need
# composer. Run this on any machine that HAS composer (local dev or staging).
#
# Usage:  deploy/build-release.sh [output.tar.gz]
# Output: /tmp/sovereign-kanban-release.tar.gz (default)
#
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-/tmp/sovereign-kanban-release.tar.gz}"
APPS=(sovereign-kanban sovereign-kanban-md-persistence sovereign-kanban-import)

command -v composer >/dev/null || { echo "composer is required on the build machine"; exit 1; }

echo "→ Installing backend vendor (prod, optimized autoloader)…"
( cd "$REPO/apps/sovereign-kanban-md-persistence" \
  && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "→ Packing $OUT …"
tar -czf "$OUT" -C "$REPO/apps" \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='*.map' \
  "${APPS[@]}"

echo "✓ Built $OUT"
echo "  apps:    ${APPS[*]}"
echo "  vendor:  $(tar -tzf "$OUT" | grep -c '/vendor/') files bundled"
echo "  version: $(grep -oP '(?<=<version>)[^<]+' "$REPO/apps/sovereign-kanban-md-persistence/appinfo/info.xml")"
