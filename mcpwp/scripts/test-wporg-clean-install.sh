#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd "$script_dir/../.." && pwd -P)"
harness_dir="$repo_root/mcpwp/tests/integration/wporg-clean-install"

# The pre_http_request spy covers these exact contexts in each lane. Keeping the
# list here also makes accidental loss of a required approval operation visible
# to the source-level gate.
readonly required_contexts='activation admin_setup admin_tools admin_settings admin_activity key_create key_list key_revoke site_info wp_site_info wp_list_posts wp_get_post wp_create_post wp_update_post wp_list_pages wp_get_page wp_create_page wp_update_page wp_list_drafts wp_delete_draft wp_list_media wp_get_media wp_upload_media_b64 wp_list_categories wp_list_tags wp_create_term denied_scope denied_capability deactivation reactivation uninstall'
: "$required_contexts"

usage() {
  echo "Usage: MCPWP_WPORG_ZIP=/absolute/mumega-mcp-3.4.13.zip $0 [wp61|wp70|all]" >&2
  echo "   or: $0 /absolute/mumega-mcp-3.4.13.zip [wp61|wp70|all]" >&2
  exit 64
}

if [[ -n "${MCPWP_WPORG_ZIP:-}" ]]; then
  lane="${1:-all}"
  [[ $# -le 1 ]] || usage
else
  [[ $# -ge 1 && $# -le 2 ]] || usage
  MCPWP_WPORG_ZIP="$1"
  lane="${2:-all}"
fi

case "$lane" in
  wp61|wp70|all) ;;
  *) usage ;;
esac

[[ -f "$MCPWP_WPORG_ZIP" && -r "$MCPWP_WPORG_ZIP" && ! -L "$MCPWP_WPORG_ZIP" ]] || {
  echo "MCPWP_WPORG_ZIP must name a readable, existing, non-symlink regular file." >&2
  exit 66
}

candidate_dir="$(cd "$(dirname "$MCPWP_WPORG_ZIP")" && pwd -P)"
candidate="$candidate_dir/$(basename "$MCPWP_WPORG_ZIP")"
[[ "$(basename "$candidate")" == 'mumega-mcp-3.4.13.zip' ]] || {
  echo "The immutable candidate filename must be mumega-mcp-3.4.13.zip." >&2
  exit 65
}

bash "$script_dir/verify-wporg-artifact.sh" "$candidate"
frozen_sha="$(shasum -a 256 "$candidate" | awk '{print $1}')"
frozen_size="$(wc -c < "$candidate" | tr -d ' ')"
export MCPWP_WPORG_ZIP="$candidate"
export MCPWP_WPORG_SHA256="$frozen_sha"

run_lane() (
  local selected="$1"
  local project
  case "$selected" in
    wp61)
      export MCPWP_WORDPRESS_IMAGE='wordpress:6.1.1-php7.4-apache@sha256:7e46cf3373751b6d62b7a0fc3a7d6686f641a34a2a0eb18947da5375c55fd009'
      export MCPWP_WPORG_EXPECTED_WP='6.1.1'
      export MCPWP_WPORG_EXPECTED_PHP='7.4'
      ;;
    wp70)
      export MCPWP_WORDPRESS_IMAGE='wordpress:7.0.1-php8.3-apache@sha256:e36cd332ae0de78f3c3691e468c54c5540c0a74286b51eb9b5964fa1e9c916cd'
      export MCPWP_WPORG_EXPECTED_WP='7.0.1'
      export MCPWP_WPORG_EXPECTED_PHP='8.3'
      ;;
  esac

  project="mcpwp-wporg-${selected}-$$-${RANDOM}"
  cleanup_lane() {
    if [[ "${KEEP_MCPWP_WPORG:-0}" != '1' ]]; then
      docker compose -f "$harness_dir/docker-compose.yml" -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi
  }
  trap cleanup_lane EXIT

  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" up -d --wait --pull never
  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" cp "$candidate" wordpress:/opt/mcpwp-candidate.zip
  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-wporg-harness/install.php
  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" exec -T --user www-data wordpress php /opt/mcpwp-wporg-harness/integration.php
  docker compose -f "$harness_dir/docker-compose.yml" -p "$project" exec -T --user www-data wordpress sh -eu -c 'test ! -s /var/www/html/wp-content/mcpwp-wporg-http-attempts.jsonl; test ! -s /var/www/html/wp-content/debug.log'

  local after_sha after_size
  after_sha="$(shasum -a 256 "$candidate" | awk '{print $1}')"
  after_size="$(wc -c < "$candidate" | tr -d ' ')"
  [[ "$after_sha" == "$frozen_sha" && "$after_size" == "$frozen_size" ]] || {
    echo "The frozen candidate changed while the $selected lane was running." >&2
    exit 1
  }
  echo "$selected passed exact-ZIP clean-install gate: $frozen_sha ($frozen_size bytes)"
)

if [[ "$lane" == 'all' ]]; then
  run_lane wp61
  run_lane wp70
else
  run_lane "$lane"
fi
