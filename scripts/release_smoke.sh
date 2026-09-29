#!/usr/bin/env bash
#
# Release smoke test: upgrade a real WordPress from the LIVE update-channel ZIP to a
# CANDIDATE ZIP, then exercise the plugin over real HTTP.
#
# Run it before merging a release PR. It uses throwaway containers with unique names
# and removes them (by exact name) at the end. It never touches wp-rig-* containers
# except to COPY Elementor Pro out of wp-rig-wp-dev (read-only).
#
# Usage:
#   bash scripts/release_smoke.sh --candidate mcpwp/scripts/mcpwp-selfhosted-3.14.9.zip
#
# Options:
#   --candidate PATH   candidate ZIP (required). Build it with
#                      `bash mcpwp/scripts/build-selfhosted.sh`
#   --baseline-url URL live ZIP to start from (default https://updates.mcpwp.net/mcpwp-latest.zip)
#   --port N           host port for the throwaway site (default 9190)
#   --name PREFIX      container name prefix (default smk)
#   --keep             leave the containers running afterwards (you must remove them yourself)
#
# Exit codes (same contract as ci-watch):
#   0  every check ran and passed
#   1  the site came up and at least one check FAILED
#   2  could not run (bad args, docker problem, site never came up). NOT a pass.
#
# Add release-specific checks in the RELEASE_CHECKS section at the bottom, or run them
# by hand against http://localhost:$PORT while --keep holds the site up.

set -uo pipefail

CANDIDATE=""
BASELINE_URL="https://updates.mcpwp.net/mcpwp-latest.zip"
PORT=9190
NAME=smk
KEEP=0

while [[ $# -gt 0 ]]; do
	case "$1" in
		--candidate) CANDIDATE="${2:-}"; shift 2 ;;
		--baseline-url) BASELINE_URL="${2:-}"; shift 2 ;;
		--port) PORT="${2:-}"; shift 2 ;;
		--name) NAME="${2:-}"; shift 2 ;;
		--keep) KEEP=1; shift ;;
		-h|--help) sed -n 2,30p "$0"; exit 0 ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

[[ -n "$CANDIDATE" && -f "$CANDIDATE" ]] || { echo "--candidate ZIP not found: '$CANDIDATE'" >&2; exit 2; }
CANDIDATE="$(cd "$(dirname "$CANDIDATE")" && pwd)/$(basename "$CANDIDATE")"

NET="$NAME-net"; DB="$NAME-db"; WP="$NAME-wp"
URL="http://localhost:$PORT"
WORK="$(mktemp -d)"
PASS=0; FAIL=0; FAILED_NAMES=()

for c in "$DB" "$WP"; do
	if [[ -n "$(docker ps -aq --filter "name=^${c}\$")" ]]; then
		echo "Container $c already exists. Pick another --name, or remove it: docker rm -f -v $c" >&2
		exit 2
	fi
done

teardown() {
	if [[ "$KEEP" -eq 1 ]]; then
		echo ""
		echo "--keep: site left up at $URL. Remove later with:"
		echo "  docker rm -f -v $WP $DB && docker network rm $NET"
	else
		docker rm -f -v "$WP" "$DB" >/dev/null 2>&1
		docker network rm "$NET" >/dev/null 2>&1
	fi
	rm -rf "$WORK"
}
trap teardown EXIT

die2() { echo "COULD NOT RUN: $*" >&2; echo "NO RESULT OBSERVED. Do not infer success from this exit."; exit 2; }

wpcli() {
	docker run --rm -i --network "$NET" --volumes-from "$WP" --user 33:33 \
		-e WORDPRESS_DB_HOST="$DB:3306" -e WORDPRESS_DB_USER=smk -e WORDPRESS_DB_PASSWORD=smkpass \
		-e WORDPRESS_DB_NAME=smk_wp -e HOME=/tmp \
		wordpress:cli wp "$@"
}

# Match helpers. Never pipe into `grep -q` under pipefail: grep exits on the first match,
# the writer gets SIGPIPE, and the pipeline reports failure for a successful match.
has()  { grep -q  -- "$2" <<< "$1"; }
hasi() { grep -qi -- "$2" <<< "$1"; }

check() {
	# check NAME CONDITION-RESULT(0/1) DETAIL
	local name="$1" ok="$2" detail="${3:-}"
	if [[ "$ok" -eq 0 ]]; then
		PASS=$((PASS + 1)); echo "  PASS  $name"
	else
		FAIL=$((FAIL + 1)); FAILED_NAMES+=("$name"); echo "  FAIL  $name :: ${detail:0:300}"
	fi
}

rpc() {
	# rpc METHOD PARAMS_JSON  -> prints JSON-RPC response body
	curl -s -m 60 -X POST "$URL/wp-json/mcpwp/v1/mcp" -H "X-API-Key: $KEY" \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		-d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$1\",\"params\":$2}"
}

tool() {
	# tool NAME ARGS_JSON -> prints the tool's first text content (the JSON payload)
	rpc tools/call "{\"name\":\"$1\",\"arguments\":$2}" | jq -r '.result.content[0].text // .error.message // empty'
}

echo "==> Throwaway site $WP on $URL"
docker network create "$NET" >/dev/null || die2 "docker network create"
docker run -d --name "$DB" --network "$NET" \
	-e MARIADB_DATABASE=smk_wp -e MARIADB_USER=smk -e MARIADB_PASSWORD=smkpass -e MARIADB_ROOT_PASSWORD=smkroot \
	mariadb:11 >/dev/null || die2 "start $DB"
docker run -d --name "$WP" --network "$NET" -p "$PORT:80" \
	-e WORDPRESS_DB_HOST="$DB:3306" -e WORDPRESS_DB_USER=smk -e WORDPRESS_DB_PASSWORD=smkpass -e WORDPRESS_DB_NAME=smk_wp \
	-e WORDPRESS_DEBUG=1 \
	-e WORDPRESS_CONFIG_EXTRA="define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);" \
	wordpress:php8.3-apache >/dev/null || die2 "start $WP"

for i in $(seq 1 60); do
	curl -s -o /dev/null -m 5 "$URL/wp-login.php" && docker exec "$DB" mariadb -usmk -psmkpass -e 'select 1' smk_wp >/dev/null 2>&1 && break
	sleep 2
	[[ $i -eq 60 ]] && die2 "site never answered on $URL"
done

wpcli core install --url="$URL" --title="MCPWP smoke" --admin_user=admin --admin_password=admin \
	--admin_email=smoke@example.com --skip-email >/dev/null || die2 "wp core install"
wpcli rewrite structure '/%postname%/' >/dev/null
# Install then activate one by one: Elementor's activation hook can throw under WP-CLI
# after it has already activated, which would otherwise stop WooCommerce activating.
wpcli plugin install elementor woocommerce > "$WORK/plugins.log" 2>&1 || echo "  (warn) plugin download failed; see below"
for p in elementor woocommerce; do wpcli plugin activate "$p" >> "$WORK/plugins.log" 2>&1; done
for p in elementor woocommerce; do
	wpcli plugin is-active "$p" 2>/dev/null || { echo "  (warn) $p not active; continuing:"; tail -3 "$WORK/plugins.log"; }
done

if [[ -n "$(docker ps -q --filter 'name=^wp-rig-wp-dev$')" ]]; then
	docker cp wp-rig-wp-dev:/var/www/html/wp-content/plugins/elementor-pro "$WORK/elementor-pro" >/dev/null 2>&1 \
		&& docker cp "$WORK/elementor-pro" "$WP:/var/www/html/wp-content/plugins/elementor-pro" >/dev/null \
		&& docker exec "$WP" chown -R 33:33 /var/www/html/wp-content/plugins/elementor-pro \
		&& wpcli plugin activate elementor-pro >/dev/null 2>&1 \
		|| echo "  (warn) Elementor Pro copy/activate failed; continuing without it"
fi

echo "==> Baseline: $BASELINE_URL"
curl -sfL -m 120 -o "$WORK/baseline.zip" "$BASELINE_URL" || die2 "download baseline ZIP"
# wp-cli runs in its own container sharing only /var/www/html, so ZIPs go under wp-content.
docker cp "$WORK/baseline.zip" "$WP:/var/www/html/wp-content/smk-baseline.zip" && docker exec "$WP" chown 33:33 /var/www/html/wp-content/smk-baseline.zip
wpcli plugin install /var/www/html/wp-content/smk-baseline.zip --activate >/dev/null || die2 "install baseline"
BASE_VER=$(wpcli plugin get mcpwp --field=version)
echo "    baseline version $BASE_VER"

KEY="mcpwp_$(openssl rand -hex 24)"
wpcli eval "update_option('mcpwp_api_key', wp_hash_password('$KEY'));" >/dev/null || die2 "create API key"

# Seed content on the baseline so the upgrade has something to migrate.
SEED_ID=$(wpcli post create --post_type=page --post_status=publish --post_title='Smoke seed page' --porcelain)
curl -s -o /dev/null "$URL/wp-json/mcpwp/v1/site-info" -H "X-API-Key: $KEY"

echo "==> Upgrade to candidate: $(basename "$CANDIDATE")"
docker cp "$CANDIDATE" "$WP:/var/www/html/wp-content/smk-candidate.zip" && docker exec "$WP" chown 33:33 /var/www/html/wp-content/smk-candidate.zip
wpcli plugin install /var/www/html/wp-content/smk-candidate.zip --force >/dev/null || die2 "install candidate"
CAND_VER=$(unzip -p "$CANDIDATE" mcpwp/mcpwp.php | grep -m1 'Version:' | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
# First requests after the swap run the upgrade routines.
curl -s -o /dev/null "$URL/"; curl -s -o /dev/null "$URL/wp-admin/admin-ajax.php"

echo "==> Checks"

# 1. site-info answers and reports the candidate version.
SI=$(curl -s -m 30 "$URL/wp-json/mcpwp/v1/site-info" -H "X-API-Key: $KEY")
[[ "$(wpcli plugin get mcpwp --field=version)" == "$CAND_VER" ]]; check "installed version is $CAND_VER" $? "$(wpcli plugin get mcpwp --field=version)"
[[ -n "$SI" ]] && echo "$SI" | jq -e . >/dev/null 2>&1; check "site-info returns JSON" $? "$SI"

# 2. Unauthenticated call is refused.
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-json/mcpwp/v1/pages")
[[ "$code" == "401" || "$code" == "403" ]]; check "no key -> 401/403 (got $code)" $?

# 3. MCP handshake + tools/list.
INIT=$(rpc initialize '{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}')
echo "$INIT" | jq -e '.result.protocolVersion' >/dev/null 2>&1; check "MCP initialize" $? "$INIT"
NT=$(rpc tools/list '{}' | jq '.result.tools | length' 2>/dev/null)
[[ "${NT:-0}" -ge 20 ]]; check "tools/list returns tools ($NT)" $?

# 4. Create a page through MCP, write Elementor data, read it back, render it.
CR=$(tool wp_create_page '{"title":"Smoke MCP page","status":"publish"}')
PID=$(echo "$CR" | jq -r '.id // .page_id // .page.id // empty' 2>/dev/null)
[[ "$PID" =~ ^[0-9]+$ ]]; check "wp_create_page -> id ($PID)" $? "$CR"
if [[ "$PID" =~ ^[0-9]+$ ]]; then
	EL='[{"id":"a1b2c3d","elType":"section","settings":{},"elements":[{"id":"b2c3d4e","elType":"column","settings":{"_column_size":100},"elements":[{"id":"c3d4e5f","elType":"widget","widgetType":"heading","settings":{"title":"Smoke heading 7Q"}}]}]}]'
	ELJ=$(jq -Rn --arg d "$EL" --argjson id "$PID" '{id:$id, elementor_data:$d}')
	SE=$(tool wp_set_elementor "$ELJ")
	echo "$SE" | jq -e '.success == true' >/dev/null 2>&1; check "wp_set_elementor success" $? "$SE"
	GE=$(tool wp_get_elementor "{\"id\":$PID}")
	has "$GE" 'Smoke heading 7Q'; check "wp_get_elementor reads back" $? "$GE"
	# The first front-end hit after WooCommerce activation can land before rewrite rules
	# settle, so allow three tries before calling it a failure.
	rendered=1
	for _ in 1 2 3; do
		PAGE_HTML=$(curl -sL -m 30 "$URL/?page_id=$PID")
		has "$PAGE_HTML" 'Smoke heading 7Q' && { rendered=0; break; }
		sleep 3
	done
	check "front end renders the heading" $rendered "$(curl -sL -m 30 -o /dev/null -w '%{http_code} %{url_effective}' "$URL/?page_id=$PID")"
	UP=$(tool wp_update_page "{\"id\":$PID,\"title\":\"Smoke MCP page (edited)\"}")
	hasi "$UP" 'edited'; check "wp_update_page" $? "$UP"
fi

# 5. Seed page from the baseline is still readable after the upgrade.
GS=$(curl -s -m 30 "$URL/wp-json/mcpwp/v1/pages/$SEED_ID" -H "X-API-Key: $KEY")
has "$GS" 'Smoke seed page'; check "baseline content readable after upgrade" $? "$GS"

# 6. Action log records the MCP writes.
AL=$(curl -s -m 30 "$URL/wp-json/mcpwp/v1/action-log?per_page=20" -H "X-API-Key: $KEY")
echo "$AL" | jq -e '(.entries // .items // .data // .) | length > 0' >/dev/null 2>&1; check "action log has entries" $? "$AL"

# 7. Approvals list answers.
AP=$(curl -s -o /dev/null -w '%{http_code}' -m 30 "$URL/wp-json/mcpwp/v1/approvals" -H "X-API-Key: $KEY")
[[ "$AP" == "200" ]]; check "approvals list 200 (got $AP)" $?

# 8. Onboard (first-contact briefing) answers.
OB=$(tool wp_onboard '{}')
[[ -n "$OB" ]] && ! hasi "$OB" 'fatal'; check "wp_onboard answers" $? "$OB"

# ---------------------------------------------------------------------------------
# RELEASE_CHECKS: add checks for this release's changes here, same pattern:
#   R=$(tool <tool_name> '<json args>'); has "$R" '<expected>'; check "<name>" $? "$R"
# ---------------------------------------------------------------------------------

# Last: no PHP fatal/warning/notice from the plugin in debug.log.
LOG=$(docker exec "$WP" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' | grep -E 'PHP (Fatal|Warning|Parse|Notice|Deprecated)' | grep -i 'mcpwp' || true)
[[ -z "$LOG" ]]; check "debug.log clean of mcpwp PHP errors" $? "$LOG"

echo ""
echo "==> $BASE_VER -> $CAND_VER: $PASS passed, $FAIL failed"
if [[ "$FAIL" -gt 0 ]]; then
	printf '    failed: %s\n' "${FAILED_NAMES[@]}"
	exit 1
fi
exit 0
