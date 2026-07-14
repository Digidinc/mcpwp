#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
project="${MCPWP_WP70_PROJECT:-mcpwp-wp70-$$-$RANDOM}"

cleanup() {
  if [[ "${KEEP_WP70:-0}" != "1" ]]; then
    docker compose -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

docker compose -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
docker compose -p "$project" up -d --wait --pull never
docker compose -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-integration/install.php
docker compose -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-integration/integration.php
