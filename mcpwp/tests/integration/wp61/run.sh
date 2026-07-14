#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
project="${MCPWP_WP61_PROJECT:-mcpwp-wp61-$$-$RANDOM}"

cleanup() {
  if [[ "${KEEP_WP61:-0}" != "1" ]]; then
    docker compose -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# Remove residue when an explicit debugging project override is reused.
docker compose -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
docker compose -p "$project" up -d --wait --pull never
docker compose -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-integration/install.php
docker compose -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-integration/integration.php
