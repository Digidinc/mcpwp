#!/usr/bin/env bash
#
# Regenerate the docs.mcpwp.net tool, endpoint, error and changelog reference (#1065).
#
# Stands up a throwaway site with scripts/release_smoke.sh --keep, adds the plugins whose
# tools are capability-gated (Yoast SEO, Contact Form 7, LearnPress, WP Events Manager; the
# smoke site already has Elementor and WooCommerce; Elementor Pro only when
# GEN_ELEMENTOR_PRO_DIR points at a copy), reads the catalog once without and once with the
# agency marker (Free, then Pro), renders the Markdown into the docs checkout, and removes the containers.
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
# Environment:
#   GEN_ELEMENTOR_PRO_DIR   host path to an elementor-pro plugin directory OUTSIDE any shared
#                           rig. Without it the Elementor-Pro-gated tools are MISSING from the
#                           snapshot, a loud warning is printed, and the render step refuses
#                           (the output would not be a complete reference) unless
#                           GEN_ALLOW_INCOMPLETE=1, which renders it labelled INCOMPLETE.
#   GEN_WPCLI_MEMORY        PHP memory_limit for every WP-CLI call (default 512M), set with
#                           `php -d` before WordPress boots.
#
# The API key is a scoped key made through the plugin's own creator; the legacy single-key
# option is never written (since 3.14.11 it is ignored once any scoped key exists). The key lives only in this shell and the generator's environment; it is never written to
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
GEN_WPCLI_MEMORY="${GEN_WPCLI_MEMORY:-512M}"
GEN_ELEMENTOR_PRO_DIR="${GEN_ELEMENTOR_PRO_DIR:-}"
GEN_ALLOW_INCOMPLETE="${GEN_ALLOW_INCOMPLETE:-0}"

while [[ $# -gt 0 ]]; do
	case "$1" in
		--candidate) CANDIDATE="${2:-}"; shift 2 ;;
		--out) OUT="${2:-}"; shift 2 ;;
		--source) SOURCE="${2:-}"; shift 2 ;;
		--port) PORT="${2:-}"; shift 2 ;;
		--name) NAME="${2:-}"; shift 2 ;;
		--keep) KEEP=1; shift ;;
		-h|--help) sed -n 2,40p "$0"; exit 0 ;;
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
		wordpress:cli php -d "memory_limit=${GEN_WPCLI_MEMORY}" /usr/local/bin/wp "$@"
}

echo "==> Throwaway site (release_smoke.sh --keep)"
SMOKE_ELEMENTOR_PRO_DIR="$GEN_ELEMENTOR_PRO_DIR" SMOKE_WPCLI_MEMORY="$GEN_WPCLI_MEMORY" \
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

PRO_ACTIVE=0
wpcli plugin is-active elementor-pro >/dev/null 2>&1 && PRO_ACTIVE=1
if [[ "$PRO_ACTIVE" -ne 1 ]]; then
	echo "!!! WARNING: Elementor Pro is NOT installed (set GEN_ELEMENTOR_PRO_DIR to a copy outside the rig)." >&2
	echo "!!! Elementor-Pro-gated tools will be MISSING from the snapshot; the output is NOT a complete reference." >&2
	if [[ "$GEN_ALLOW_INCOMPLETE" != "1" ]]; then
		echo "Refusing to continue: no Elementor Pro, so the reference would be missing Pro-gated tools. Re-run with GEN_ELEMENTOR_PRO_DIR=/path/to/elementor-pro, or GEN_ALLOW_INCOMPLETE=1 to render pages labelled INCOMPLETE." >&2
		exit 1
	fi
fi

# A scoped key through the supported creator. The activation-created key store is kept: no
# delete_option, no legacy fallback. Admin role + read,write,admin scopes (the generator needs
# tools/list ?profile=full and wp_introspect, which the admin role ceiling covers).
KEYPHP='$a = get_user_by( "login", "admin" );
if ( ! $a ) { echo "GEN:ERROR:no_admin"; return; }
wp_set_current_user( $a->ID );
$c = new Mcpwp_REST_MCP();
if ( ! method_exists( $c, "create_scoped_api_key" ) ) { echo "GEN:ERROR:no_creator"; return; }
$r = $c->create_scoped_api_key( "Docs reference generator", array( "read", "write", "admin" ), "admin" );
wp_set_current_user( 0 );
if ( isset( $r["error"] ) || empty( $r["key"] ) ) { echo "GEN:ERROR:" . wp_json_encode( $r ); return; }
echo $r["key"];'
MINT_OUT="$(wpcli eval "$KEYPHP" 2>"$WORK/mint-err.log")"
KEY="$(grep -E '^mcpwp_' <<<"$MINT_OUT" | tail -1)"
if [[ -z "$KEY" ]]; then
	echo "could not create a scoped API key" >&2
	grep 'GEN:ERROR' <<<"$MINT_OUT" >&2
	grep -v '^mcpwp_' "$WORK/mint-err.log" | tail -5 >&2
	exit 2
fi

MARKER=/var/www/html/wp-content/plugins/mcpwp/includes/agency-license.php
docker exec "$WP" rm -f "$MARKER"

echo "==> Free catalog"
MCPWP_ELEMENTOR_PRO="$PRO_ACTIVE" MCPWP_SITE_URL="$URL" MCPWP_API_KEY="$KEY" node "$HERE/gen-docs-reference.mjs" fetch --edition free --source "$SOURCE" --plugin-dir "$ROOT/mcpwp" --snapshot "$WORK/free.json" || exit 1

echo "==> Pro catalog (agency marker inside the container only)"
docker exec "$WP" sh -c "printf '<?php\nreturn '\''MCPWP_AGENCY_LICENSE_V1'\'';\n' > $MARKER && chown 33:33 $MARKER" || { echo "could not write the marker" >&2; exit 2; }
MCPWP_ELEMENTOR_PRO="$PRO_ACTIVE" MCPWP_SITE_URL="$URL" MCPWP_API_KEY="$KEY" node "$HERE/gen-docs-reference.mjs" fetch --edition pro --source "$SOURCE" --plugin-dir "$ROOT/mcpwp" --snapshot "$WORK/pro.json" || exit 1
docker exec "$WP" rm -f "$MARKER"

echo "==> Render into $OUT"
RENDER_FLAGS=()
# Only reachable without Elementor Pro when GEN_ALLOW_INCOMPLETE=1 (the check above exits otherwise).
[[ "$PRO_ACTIVE" -ne 1 ]] && RENDER_FLAGS+=(--allow-incomplete)
node "$HERE/gen-docs-reference.mjs" render "${RENDER_FLAGS[@]}" --free "$WORK/free.json" --pro "$WORK/pro.json" --plugin-dir "$ROOT/mcpwp" --out "$OUT" || exit 1
if [[ "$PRO_ACTIVE" -ne 1 ]]; then
	echo "Done, but the output is INCOMPLETE (no Elementor Pro) and every generated page is labelled so. Do NOT build or open a PR from it."
else
	echo "Done. Review the diff in the docs repo, then build it (npm run build) and open a PR."
fi
