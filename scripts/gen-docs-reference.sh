#!/usr/bin/env bash
#
# Regenerate the docs.mcpwp.net tool, endpoint, error and changelog reference (#1065).
#
# Stands up a throwaway site with scripts/release_smoke.sh --keep, adds the plugins whose
# tools are capability-gated (Yoast SEO, Contact Form 7, LearnPress, WP Events Manager; the
# smoke site already has Elementor, WooCommerce and, if wp-rig-wp-dev is up, Elementor Pro),
# reads the catalog once without and once with the agency marker (Free, then Pro), renders the
# Markdown into the docs checkout, and removes the containers.
#
# Usage:
#   bash scripts/gen-docs-reference.sh --candidate mcpwp/scripts/mcpwp-selfhosted-X.zip \
#     --out ../mcpwp-docs/src/content/docs
#
# Options:
#   --candidate PATH   plugin ZIP to document (build it with bash mcpwp/scripts/build-selfhosted.sh)
#   --out DIR          the docs repo's src/content/docs directory
#   --source NAME      introspect (default) | tools-list | find-tools; see gen-docs-reference.mjs
#   --port N           host port (default 9196)
#   --name PREFIX      container prefix (default docsref)
#   --keep             leave the site up afterwards
#
# The API key lives only in this shell and the generator's environment; it is never written to
# disk or into the output. The agency marker is created inside the throwaway container only.
#
# Exit: 0 generated; 1 generation failed; 2 could not run (site never came up, bad args).

set -uo pipefail

CANDIDATE=""
OUT=""
SOURCE="introspect"
PORT=9196
NAME=docsref
KEEP=0

while [[ $# -gt 0 ]]; do
	case "$1" in
		--candidate) CANDIDATE="${2:-}"; shift 2 ;;
		--out) OUT="${2:-}"; shift 2 ;;
		--source) SOURCE="${2:-}"; shift 2 ;;
		--port) PORT="${2:-}"; shift 2 ;;
		--name) NAME="${2:-}"; shift 2 ;;
		--keep) KEEP=1; shift ;;
		-h|--help) sed -n 2,26p "$0"; exit 0 ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

[[ -n "$CANDIDATE" && -f "$CANDIDATE" ]] || { echo "--candidate ZIP not found: '$CANDIDATE'" >&2; exit 2; }
[[ -n "$OUT" && -d "$OUT" ]] || { echo "--out must be the docs repo's src/content/docs directory: '$OUT'" >&2; exit 2; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
WP="$NAME-wp"; DB="$NAME-db"; NET="$NAME-net"
URL="http://localhost:$PORT"
WORK="$(mktemp -d)"

teardown() {
	if [[ "$KEEP" -eq 1 ]]; then
		echo "--keep: site left up at $URL. Remove with: docker rm -f -v $WP $DB && docker network rm $NET"
	else
		docker rm -f -v "$WP" "$DB" >/dev/null 2>&1
		docker network rm "$NET" >/dev/null 2>&1
	fi
	rm -rf "$WORK"
}
trap teardown EXIT

wpcli() {
	docker run --rm -i --network "$NET" --volumes-from "$WP" --user 33:33 \
		-e WORDPRESS_DB_HOST="$DB:3306" -e WORDPRESS_DB_USER=smk -e WORDPRESS_DB_PASSWORD=smkpass \
		-e WORDPRESS_DB_NAME=smk_wp -e HOME=/tmp \
		wordpress:cli wp "$@"
}

echo "==> Throwaway site (release_smoke.sh --keep)"
bash "$ROOT/scripts/release_smoke.sh" --candidate "$CANDIDATE" --name "$NAME" --port "$PORT" --keep > "$WORK/smoke.log" 2>&1
smoke=$?
tail -4 "$WORK/smoke.log"
if [[ "$smoke" -ne 0 ]]; then
	echo "release_smoke.sh exited $smoke; not generating docs from a site that failed its smoke test." >&2
	exit 2
fi

echo "==> Capability-gated plugins"
# WooCommerce + LearnPress exceed WP-CLI's default 128M during activation.
docker exec "$WP" sed -i "s|^/\* That's all, stop editing|define('WP_MEMORY_LIMIT','512M');\n/* That's all, stop editing|" /var/www/html/wp-config.php
wpcli plugin install wordpress-seo contact-form-7 learnpress wp-events-manager >/dev/null 2>&1 || echo "  (warn) plugin download failed"
# WP Events Manager can fatal under WP-CLI on its first activation (a session call with no
# request) and still end up inactive; a second try activates it.
for p in wordpress-seo contact-form-7 learnpress wp-events-manager; do
	for _ in 1 2 3; do
		wpcli plugin is-active "$p" >/dev/null 2>&1 && break
		wpcli plugin activate "$p" >/dev/null 2>&1
	done
	wpcli plugin is-active "$p" >/dev/null 2>&1 || echo "  (warn) $p is not active; its tools will be missing from the reference"
done
wpcli plugin list --status=active --field=name 2>/dev/null | tr '\n' ' '; echo

KEY="mcpwp_$(openssl rand -hex 24)"
wpcli eval "update_option('mcpwp_api_key', wp_hash_password('$KEY'));" >/dev/null || { echo "could not create an API key" >&2; exit 2; }

MARKER=/var/www/html/wp-content/plugins/mcpwp/includes/agency-license.php
docker exec "$WP" rm -f "$MARKER"

echo "==> Free catalog"
MCPWP_SITE_URL="$URL" MCPWP_API_KEY="$KEY" node "$HERE/gen-docs-reference.mjs" fetch --edition free --source "$SOURCE" --snapshot "$WORK/free.json" || exit 1

echo "==> Pro catalog (agency marker inside the container only)"
docker exec "$WP" sh -c "printf '<?php\nreturn '\''MCPWP_AGENCY_LICENSE_V1'\'';\n' > $MARKER && chown 33:33 $MARKER" || { echo "could not write the marker" >&2; exit 2; }
MCPWP_SITE_URL="$URL" MCPWP_API_KEY="$KEY" node "$HERE/gen-docs-reference.mjs" fetch --edition pro --source "$SOURCE" --snapshot "$WORK/pro.json" || exit 1
docker exec "$WP" rm -f "$MARKER"

echo "==> Render into $OUT"
node "$HERE/gen-docs-reference.mjs" render --free "$WORK/free.json" --pro "$WORK/pro.json" --plugin-dir "$ROOT/mcpwp" --out "$OUT" || exit 1
echo "Done. Review the diff in the docs repo, then build it (npm run build) and open a PR."
