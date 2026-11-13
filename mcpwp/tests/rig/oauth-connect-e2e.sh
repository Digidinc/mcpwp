#!/usr/bin/env bash
# End-to-end check of the plugin's own OAuth connect flow against a REAL WordPress.
#
# Stands up a throwaway stack (MariaDB + WordPress/Apache, all named e2e-oauth-*,
# its own network and volumes), installs and activates the plugin from THIS
# checkout, then drives the whole flow over HTTP the way claude.ai or ChatGPT
# would: discovery, dynamic client registration, PKCE authorize with a real
# browser-style login and consent screen, token exchange, /mcp calls, scope
# limits, refresh rotation, RFC 7009 revocation and authorization-code replay.
#
# Usage (from anywhere; needs docker, curl, jq, openssl, python3 on the host):
#
#   bash mcpwp/tests/rig/oauth-connect-e2e.sh            # run, then tear everything down
#   bash mcpwp/tests/rig/oauth-connect-e2e.sh --keep     # run, leave the stack up for a human
#   bash mcpwp/tests/rig/oauth-connect-e2e.sh --down     # remove a stack left by --keep
#
# Options / environment:
#   --keep               After the run, leave the stack up and print the MCP URL and a
#                        one-time administrator login (random password, shown once on
#                        the terminal only) for a joint manual test.
#   --down               Remove the e2e-oauth-* containers, network and volumes, then exit.
#   --site-url URL       With --keep: after the checks pass, repoint the site at URL (for
#                        example an https tunnel to the printed local port) so a real
#                        claude.ai / ChatGPT connector can reach it. Those connectors
#                        cannot be exercised from CI; this is the human half of the test.
#   E2E_PORT=18931       Host port (bound to 127.0.0.1 only).
#   E2E_WP_IMAGE / E2E_DB_IMAGE / E2E_CLI_IMAGE   Override the images.
#
# Safety: only objects named e2e-oauth-* are ever created or removed (never wp-rig-*,
# never test-rig), and nothing is pruned. The plugin is COPIED into the throwaway
# volume, so the checkout is never written to and the shared dev rig is not touched.
# Passwords are generated at run time, kept in a 0700 scratch dir that is deleted on
# exit, and printed only by --keep. Nothing here is committed or logged.
#
# One stack at a time: the fixed names make a second run refuse to start until the
# first is gone (--down). Exits non-zero if any step fails.

set -uo pipefail

# ---------------------------------------------------------------- config
PREFIX=e2e-oauth
NET=$PREFIX-net
DB=$PREFIX-db
WP=$PREFIX-wp
VOL_DB=$PREFIX-db-data
VOL_WP=$PREFIX-wp-data
WP_IMAGE=${E2E_WP_IMAGE:-wordpress:php8.3-apache}
DB_IMAGE=${E2E_DB_IMAGE:-mariadb:lts}
CLI_IMAGE=${E2E_CLI_IMAGE:-wordpress:cli}
PORT=${E2E_PORT:-18931}
SITE="http://localhost:$PORT"
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PLUGIN_SRC=$(cd "$HERE/../.." && pwd)   # .../mcpwp

KEEP=0 DOWN=0 SITE_URL_OVERRIDE=""
while [[ $# -gt 0 ]]; do
	case $1 in
		--keep) KEEP=1 ;;
		--down) DOWN=1 ;;
		--site-url) SITE_URL_OVERRIDE=${2:-}; shift ;;
		-h|--help) sed -n '2,38p' "$0"; exit 0 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
	shift
done

down() {
	docker rm -f "$WP" "$DB" >/dev/null 2>&1
	docker network rm "$NET" >/dev/null 2>&1
	docker volume rm "$VOL_WP" "$VOL_DB" >/dev/null 2>&1
	return 0
}

if [[ $DOWN -eq 1 ]]; then
	down
	echo "removed $PREFIX-* containers, network and volumes"
	exit 0
fi

for bin in docker curl jq openssl python3; do
	command -v "$bin" >/dev/null || { echo "missing host tool: $bin" >&2; exit 2; }
done
if docker ps -a --format '{{.Names}}' | grep -q "^\($WP\|$DB\)$"; then
	echo "A $PREFIX stack already exists. Run: bash $0 --down" >&2
	exit 2
fi
if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
	echo "Port $PORT is in use; set E2E_PORT." >&2
	exit 2
fi

# Scratch dir on a home volume (/tmp is a small shared tmpfs).
SCRATCH=$(mktemp -d -p "${E2E_TMPDIR:-$HOME/.cache}" oauth-e2e.XXXXXX) || exit 2
chmod 700 "$SCRATCH"
FAILED=0
cleanup() {
	rm -rf "$SCRATCH"
	if [[ $KEEP -eq 1 && $FAILED -eq 0 ]]; then return; fi
	if [[ $KEEP -eq 1 ]]; then echo "run failed; keeping the stack for inspection (bash $0 --down to remove)"; return; fi
	down
}
trap cleanup EXIT
trap 'exit 130' INT TERM

pass=0 fail=0
ok()  { echo "PASS  $1"; pass=$((pass + 1)); }
bad() { echo "FAIL  $1${2:+ -- $2}"; fail=$((fail + 1)); FAILED=1; }
check() { # description, actual, expected
	if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi
}
check_re() { # description, actual, regex
	if [[ "$2" =~ $3 ]]; then ok "$1"; else bad "$1" "[$2] !~ /$3/"; fi
}
die() { echo "FATAL $*" >&2; FAILED=1; exit 1; }

wpcli() { docker run --rm --network "$NET" --volumes-from "$WP" -e WORDPRESS_DB_HOST=$DB \
	-e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DBPW" -e WORDPRESS_DB_NAME=wp \
	-u 33:33 "$CLI_IMAGE" wp "$@"; }

rand() { openssl rand -hex "$1"; }
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

# ---------------------------------------------------------------- stack
echo "== stack: $WP + $DB on $SITE"
DBPW=$(rand 16)
docker network create "$NET" >/dev/null || die "network"
docker volume create "$VOL_DB" >/dev/null && docker volume create "$VOL_WP" >/dev/null || die "volumes"
docker run -d --name "$DB" --network "$NET" -v "$VOL_DB":/var/lib/mysql \
	-e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD="$DBPW" \
	-e MARIADB_RANDOM_ROOT_PASSWORD=1 "$DB_IMAGE" >/dev/null || die "db container"
docker run -d --name "$WP" --network "$NET" -v "$VOL_WP":/var/www/html -p "127.0.0.1:$PORT:80" \
	-e WORDPRESS_DB_HOST=$DB -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DBPW" \
	-e WORDPRESS_DB_NAME=wp "$WP_IMAGE" >/dev/null || die "wordpress container"

for _ in $(seq 1 90); do
	docker exec "$DB" sh -c 'mariadb-admin ping -uwp -p"$MARIADB_PASSWORD" --silent' >/dev/null 2>&1 && break
	sleep 1
done
for _ in $(seq 1 90); do [[ -f /dev/null ]] && docker exec "$WP" test -f /var/www/html/wp-config.php 2>/dev/null && break; sleep 1; done
docker exec "$WP" test -f /var/www/html/wp-config.php || die "wp-config never appeared"

# Copy the plugin (no vendor, no tests, no node_modules) into the throwaway volume.
docker exec "$WP" mkdir -p /var/www/html/wp-content/plugins/mcpwp
tar -C "$PLUGIN_SRC" --exclude=./vendor --exclude=./tests --exclude=./node_modules --exclude=./.git -cf - . \
	| docker exec -i "$WP" tar -C /var/www/html/wp-content/plugins/mcpwp -xf - || die "plugin copy"
docker exec "$WP" chown -R www-data:www-data /var/www/html/wp-content || die "chown"

ADMIN_PW=$(rand 16); EDITOR_PW=$(rand 16); SUB_PW=$(rand 16)
wpcli core install --url="$SITE" --title="OAuth E2E" --admin_user=e2eadmin \
	--admin_password="$ADMIN_PW" --admin_email=e2e-admin@example.invalid --skip-email >/dev/null || die "wp core install"
wpcli rewrite structure '/%postname%/' --hard >/dev/null || die "permalinks"
wpcli plugin activate mcpwp >/dev/null 2>"$SCRATCH/activate.err" || { cat "$SCRATCH/activate.err" >&2; die "plugin activate"; }
wpcli user create e2eeditor e2e-editor@example.invalid --role=editor --user_pass="$EDITOR_PW" >/dev/null || die "editor"
wpcli user create e2esub e2e-sub@example.invalid --role=subscriber --user_pass="$SUB_PW" >/dev/null || die "subscriber"
# Rate limiting would only add noise to a single-client run.
wpcli option update mcpwp_rate_limit_settings '{"enabled":false}' --format=json >/dev/null 2>&1 || true

for _ in $(seq 1 60); do
	[[ $(curl -s -o /dev/null -w '%{http_code}' "$SITE/wp-login.php") == 200 ]] && break
	sleep 1
done
ok "stack up: WordPress $(wpcli core version 2>/dev/null) with mcpwp $(wpcli plugin get mcpwp --field=version 2>/dev/null)"

MCP="$SITE/wp-json/mcpwp/v1/mcp"
REDIRECT="https://client.example/callback"
HDR="$SCRATCH/h"

# ---------------------------------------------------------------- helpers
mcp() { # token method params-json -> response body
	curl -s -X POST "$MCP" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
		-H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-06-18' \
		-d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$2\",\"params\":$3}"
}
call_tool() {
	local out
	out=$(mcp "$1" tools/call "{\"name\":\"$2\",\"arguments\":${3:-{\}}}")
	[[ -n ${E2E_DEBUG:-} ]] && echo "DEBUG $2 -> $(head -c 400 <<<"$out")" >&2
	printf '%s' "$out"
}

# A tool call the server refused: either a REST error body (insufficient_scope, 403)
# or an MCP isError result. Prints "refused" or "allowed".
refusal() { jq -r 'if (.code // "") != "" then "refused" elif .result.isError == true then "refused" elif .error then "refused" else "allowed" end' <<<"$1"; }

login() { # jar user passwordfile
	curl -s -o /dev/null -c "$1" -b "$1" -H 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' \
		--data-urlencode "log=$2" --data-urlencode "pwd@$3" --data-urlencode 'wp-submit=Log In' \
		--data-urlencode "redirect_to=$SITE/wp-admin/" "$SITE/wp-login.php"
	grep -q wordpress_logged_in "$1"
}

# Pull every hidden input of the consent form as name<TAB>value, entity-decoded.
consent_fields() {
	python3 -I -c '
import sys, html
from html.parser import HTMLParser
class P(HTMLParser):
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "input" and a.get("type") == "hidden":
            print(a["name"] + "\t" + html.unescape(a.get("value") or ""))
P().feed(sys.stdin.read())'
}

new_pkce() { # sets VERIFIER and CHALLENGE
	VERIFIER=$(openssl rand 48 | b64url)
	CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | b64url)
}

AUTH_URL=""   # filled from discovery
authorize_url() { # client_id scope state -> URL
	python3 -I -c '
import sys, urllib.parse as u
base, cid, scope, state, red, chal = sys.argv[1:7]
q = {"response_type": "code", "client_id": cid, "redirect_uri": red, "code_challenge": chal,
     "code_challenge_method": "S256", "state": state, "resource": sys.argv[7]}
if scope: q["scope"] = scope
print(base + ("&" if "?" in base else "?") + u.urlencode(q))' "$AUTH_URL" "$1" "$2" "$3" "$REDIRECT" "$CHALLENGE" "$MCP"
}

# Walk the consent screen as a logged-in user. Leaves the page in $SCRATCH/consent.html
# and the redirect Location in $LOCATION. $4 = "write" ticks the write box, else unticked.
do_consent() { # jar client_id scope state tick
	local jar=$1 cid=$2 scope=$3 state=$4 tick=${5:-write}
	curl -s -b "$jar" -c "$jar" -o "$SCRATCH/consent.html" -w '%{http_code}' "$(authorize_url "$cid" "$scope" "$state")" >"$SCRATCH/consent.code"
	CONSENT_CODE=$(<"$SCRATCH/consent.code")
	[[ $CONSENT_CODE == 200 ]] || { LOCATION=""; return 1; }
	local args=() name value
	while IFS=$'\t' read -r name value; do args+=(--data-urlencode "$name=$value"); done < <(consent_fields <"$SCRATCH/consent.html")
	[[ $tick == write ]] && args+=(--data-urlencode "scope_write=1")
	args+=(--data-urlencode "action=approve")
	curl -s -b "$jar" -c "$jar" -D "$HDR" -o /dev/null "${args[@]}" "$AUTH_URL"
	LOCATION=$(awk 'tolower($1)=="location:"{sub(/\r$/,"");print $2}' "$HDR")
	[[ -n $LOCATION ]]
}
qparam() { python3 -I -c 'import sys,urllib.parse as u; q=u.parse_qs(u.urlparse(sys.argv[1]).query); print(q.get(sys.argv[2],[""])[0])' "$1" "$2"; }

token_post() { curl -s -w '\n%{http_code}' -X POST "$TOKEN_EP" "$@"; }
split_resp() { RESP_BODY=$(sed '$d' <<<"$1"); RESP_CODE=$(tail -n1 <<<"$1"); }

# ================================================================ (a)
echo "== (a) unauthenticated /mcp"
curl -s -D "$HDR" -o "$SCRATCH/a.body" -X POST "$MCP" -H 'Content-Type: application/json' \
	-d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' -w '%{http_code}' >"$SCRATCH/a.code"
check "(a) POST /mcp without credentials -> 401" "$(<"$SCRATCH/a.code")" 401
WWW=$(awk 'tolower($1)=="www-authenticate:"{sub(/\r$/,"");print}' "$HDR")
check_re "(a) WWW-Authenticate advertises resource_metadata" "$WWW" 'Bearer resource_metadata="[^"]*/\.well-known/oauth-protected-resource'
GETCODE=$(curl -s -o /dev/null -w '%{http_code}' "$MCP")
check_re "(a) GET /mcp without credentials is not served (401/405)" "$GETCODE" '^(401|405)$'

# ================================================================ (b)
echo "== (b) discovery"
PRM_URL=$(sed -n 's/.*resource_metadata="\([^"]*\)".*/\1/p' <<<"$WWW")
PRM=$(curl -s "$PRM_URL")
check "(b) protected-resource: resource is the MCP URL" "$(jq -r .resource <<<"$PRM")" "$MCP"
AS_ISSUER=$(jq -r '.authorization_servers[0]' <<<"$PRM")
check_re "(b) protected-resource names an authorization server" "$AS_ISSUER" '^http'
ASM=$(curl -s "$SITE/.well-known/oauth-authorization-server")
check "(b) AS metadata: issuer matches the advertised server" "$(jq -r .issuer <<<"$ASM")" "$AS_ISSUER"
check "(b) AS metadata: code_challenge_methods_supported = [S256]" "$(jq -c .code_challenge_methods_supported <<<"$ASM")" '["S256"]'
check "(b) AS metadata: response_types = [code]" "$(jq -c .response_types_supported <<<"$ASM")" '["code"]'
check "(b) AS metadata: grants = authorization_code, refresh_token" "$(jq -c .grant_types_supported <<<"$ASM")" '["authorization_code","refresh_token"]'
check "(b) AS metadata: scopes never list admin" "$(jq -c .scopes_supported <<<"$ASM")" '["read","write"]'
AUTH_URL=$(jq -r .authorization_endpoint <<<"$ASM")
TOKEN_EP=$(jq -r .token_endpoint <<<"$ASM")
REG_EP=$(jq -r .registration_endpoint <<<"$ASM")
REV_EP=$(jq -r .revocation_endpoint <<<"$ASM")
for pair in "authorization_endpoint:$AUTH_URL" "token_endpoint:$TOKEN_EP" "registration_endpoint:$REG_EP" "revocation_endpoint:$REV_EP"; do
	check_re "(b) AS metadata advertises ${pair%%:*}" "${pair#*:}" "^$SITE"
done

# ================================================================ (c)
echo "== (c) dynamic client registration"
split_resp "$(curl -s -w '\n%{http_code}' -X POST "$REG_EP" -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"E2E Test Client\",\"redirect_uris\":[\"$REDIRECT\"],\"token_endpoint_auth_method\":\"none\",\"grant_types\":[\"authorization_code\",\"refresh_token\"],\"response_types\":[\"code\"]}")"
check "(c) registration -> 201" "$RESP_CODE" 201
CLIENT_ID=$(jq -r .client_id <<<"$RESP_BODY")
check_re "(c) registration returns a client_id" "$CLIENT_ID" '.'
check "(c) registration echoes the redirect URI" "$(jq -r '.redirect_uris[0]' <<<"$RESP_BODY")" "$REDIRECT"
check "(c) registered client is public (auth method none)" "$(jq -r .token_endpoint_auth_method <<<"$RESP_BODY")" none
split_resp "$(curl -s -w '\n%{http_code}' -X POST "$REG_EP" -H 'Content-Type: application/json' -d '{"client_name":"bad","redirect_uris":["http://evil.example/cb"]}')"
check "(c) plain-http non-loopback redirect URI is refused" "$RESP_CODE" 400

# ================================================================ (d)
echo "== (d) PKCE authorize + consent (administrator)"
printf '%s' "$ADMIN_PW" >"$SCRATCH/admin.pw"; printf '%s' "$EDITOR_PW" >"$SCRATCH/editor.pw"; printf '%s' "$SUB_PW" >"$SCRATCH/sub.pw"
JAR_ADMIN="$SCRATCH/admin.jar"; JAR_EDITOR="$SCRATCH/editor.jar"; JAR_SUB="$SCRATCH/sub.jar"

# Logged out -> bounced to wp-login with the authorize URL preserved.
new_pkce
OUT=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$(authorize_url "$CLIENT_ID" "read write" st-anon)")
check_re "(d) logged-out authorize redirects to wp-login.php" "$OUT" '^302 .*wp-login\.php'

login "$JAR_ADMIN" e2eadmin "$SCRATCH/admin.pw" && ok "(d) administrator logs in (cookie jar)" || die "admin login failed"
STATE=$(rand 8)
new_pkce
ADMIN_VERIFIER=$VERIFIER
if do_consent "$JAR_ADMIN" "$CLIENT_ID" "read write" "$STATE" write; then
	ok "(d) consent screen served (200) and consent accepted (302)"
else
	bad "(d) consent flow" "consent http=$CONSENT_CODE location=[$LOCATION]"
fi
HTML=$(<"$SCRATCH/consent.html")
[[ $HTML == *"E2E Test Client"* ]] && ok "(d) consent screen names the client" || bad "(d) consent screen names the client"
[[ $HTML == *"Create and edit content"* && $HTML == *'name="scope_write"'* ]] && ok "(d) consent screen shows the granted access (read + write)" || bad "(d) consent screen shows the granted access"
[[ $HTML == *"client.example"* ]] && ok "(d) consent screen names the redirect host" || bad "(d) consent screen names the redirect host"
[[ $HTML == *"Never administrator access"* ]] && ok "(d) consent screen states admin is never granted" || bad "(d) consent screen states admin is never granted"
check_re "(d) redirect goes to the registered redirect_uri" "$LOCATION" "^$REDIRECT\?"
CODE=$(qparam "$LOCATION" code)
check_re "(d) redirect carries an authorization code" "$CODE" '^[0-9a-f]{64}$'
check "(d) state round-trips byte-exact" "$(qparam "$LOCATION" state)" "$STATE"
check "(d) RFC 9207 iss is the issuer" "$(qparam "$LOCATION" iss)" "$AS_ISSUER"

# ================================================================ (e)
echo "== (e) token exchange"
# A wrong verifier must not burn the code (checked before the claim).
split_resp "$(token_post -d grant_type=authorization_code -d "code=$CODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$(openssl rand 48 | b64url)")"
check "(e) wrong code_verifier -> 400" "$RESP_CODE" 400
check "(e) wrong code_verifier -> invalid_grant" "$(jq -r .error <<<"$RESP_BODY")" invalid_grant
split_resp "$(token_post -d grant_type=authorization_code -d "code=$CODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$ADMIN_VERIFIER")"
check "(e) code + verifier -> 200" "$RESP_CODE" 200
TOK=$RESP_BODY
ACCESS=$(jq -r .access_token <<<"$TOK"); REFRESH=$(jq -r .refresh_token <<<"$TOK")
check "(e) token_type is Bearer" "$(jq -r .token_type <<<"$TOK")" Bearer
check_re "(e) access and refresh tokens issued" "$ACCESS$REFRESH" '.{20,}'
check "(e) granted scope is read write (never admin)" "$(jq -r .scope <<<"$TOK")" "read write"
SPENT_CODE=$CODE

# ================================================================ (f)
echo "== (f) /mcp with the bearer"
R=$(mcp "$ACCESS" initialize '{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"e2e","version":"1"}}')
check_re "(f) initialize returns a protocolVersion and serverInfo" "$(jq -r '.result.protocolVersion + " " + .result.serverInfo.name' <<<"$R")" '^[0-9-]+ .+'
R=$(mcp "$ACCESS" tools/list '{}')
NTOOLS=$(jq '.result.tools | length' <<<"$R" 2>/dev/null)
[[ ${NTOOLS:-0} -gt 0 ]] && ok "(f) tools/list returns $NTOOLS tools" || bad "(f) tools/list" "$(head -c 300 <<<"$R")"
R=$(call_tool "$ACCESS" wp_site_info)
check "(f) read tool wp_site_info succeeds" "$(jq -r '.result.isError // false' <<<"$R")" false
check_re "(f) wp_site_info reports this site" "$R" 'OAuth E2E'
WHOAMI=$(wpcli user get e2eadmin --field=ID 2>/dev/null)
R=$(call_tool "$ACCESS" wp_create_page '{"title":"E2E from write grant","status":"draft"}')
check "(f) write tool succeeds under a read+write grant" "$(jq -r '.result.isError // false' <<<"$R")" false
check_re "(f) the draft page really exists in WordPress" "$(wpcli post list --post_type=page --post_status=draft --field=post_title 2>/dev/null)" 'E2E from write grant'
R=$(call_tool "$ACCESS" wp_list_api_keys)
check "(f) admin-scope tool is refused for an OAuth grant" "$(refusal "$R")" refused
check "(f) ... with insufficient_scope naming admin" "$(jq -r '.code + ":" + .data.required_scope' <<<"$R")" insufficient_scope:admin
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H 'Authorization: Bearer notatoken' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(f) a garbage bearer is 401" "$R" 401

# ================================================================ (g)
echo "== (g) scope limits"
# g1: an administrator asking for scope=admin is clamped, never granted admin.
new_pkce; V=$VERIFIER
do_consent "$JAR_ADMIN" "$CLIENT_ID" "read write admin" st-admin write || bad "(g) admin-scope consent" "http=$CONSENT_CODE"
split_resp "$(token_post -d grant_type=authorization_code -d "code=$(qparam "$LOCATION" code)" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
check "(g) scope=admin request still connects (clamped)" "$RESP_CODE" 200
check "(g) scope=admin is clamped to read write" "$(jq -r .scope <<<"$RESP_BODY")" "read write"
[[ $HTML == *'>admin<'* ]] && bad "(g) consent never offers admin" || ok "(g) consent never offers an admin option"
ADMIN_CLAMPED=$(jq -r .access_token <<<"$RESP_BODY")
R=$(call_tool "$ADMIN_CLAMPED" wp_list_api_keys)
check "(g) clamped admin grant is refused admin tools" "$(refusal "$R")" refused

# g2: an editor who leaves the write box unticked holds a read-only grant.
login "$JAR_EDITOR" e2eeditor "$SCRATCH/editor.pw" || die "editor login failed"
new_pkce; V=$VERIFIER
do_consent "$JAR_EDITOR" "$CLIENT_ID" "read write" st-ro untick || bad "(g) read-only consent" "http=$CONSENT_CODE"
split_resp "$(token_post -d grant_type=authorization_code -d "code=$(qparam "$LOCATION" code)" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
check "(g) unticked write box -> token scope is read" "$(jq -r .scope <<<"$RESP_BODY")" read
RO=$(jq -r .access_token <<<"$RESP_BODY")
R=$(call_tool "$RO" wp_site_info)
check "(g) read-only grant can call a read tool" "$(jq -r '.result.isError // false' <<<"$R")" false
R=$(call_tool "$RO" wp_create_page '{"title":"E2E must not exist","status":"draft"}')
check "(g) read-only grant cannot call a write tool" "$(refusal "$R")" refused
check "(g) ... with insufficient_scope naming write" "$(jq -r '.code + ":" + .data.required_scope' <<<"$R")" insufficient_scope:write
[[ -z $(wpcli post list --post_type=page --post_status=any --name=e2e-must-not-exist --format=ids 2>/dev/null) && \
   "$(wpcli post list --post_type=page --post_status=any --field=post_title 2>/dev/null)" != *"must not exist"* ]] \
	&& ok "(g) the refused write created nothing" || bad "(g) the refused write created a page"

# g3: a subscriber is below the minimum role and gets no consent screen.
login "$JAR_SUB" e2esub "$SCRATCH/sub.pw" || die "subscriber login failed"
new_pkce
OUT=$(curl -s -b "$JAR_SUB" -o "$SCRATCH/sub.html" -w '%{http_code}' "$(authorize_url "$CLIENT_ID" "read" st-sub)")
if [[ $OUT != 200 || $(<"$SCRATCH/sub.html") != *'name="action"'* ]]; then ok "(g) subscriber gets no consent form (http $OUT)"; else bad "(g) subscriber was shown a consent form"; fi

# ================================================================ (h)
echo "== (h) refresh-token rotation"
split_resp "$(token_post -d grant_type=refresh_token -d "refresh_token=$REFRESH" -d "client_id=$CLIENT_ID")"
check "(h) refresh -> 200" "$RESP_CODE" 200
ACCESS2=$(jq -r .access_token <<<"$RESP_BODY"); REFRESH2=$(jq -r .refresh_token <<<"$RESP_BODY")
[[ -n $REFRESH2 && $REFRESH2 != null && $REFRESH2 != "$REFRESH" ]] && ok "(h) a new refresh token replaces the old one" || bad "(h) refresh token not rotated"
check "(h) refreshed token keeps the scope" "$(jq -r .scope <<<"$RESP_BODY")" "read write"
R=$(call_tool "$ACCESS2" wp_site_info)
check "(h) the refreshed access token works" "$(jq -r '.result.isError // false' <<<"$R")" false
split_resp "$(token_post -d grant_type=refresh_token -d "refresh_token=$REFRESH" -d "client_id=$CLIENT_ID")"
check "(h) the rotated-away refresh token is refused" "$RESP_CODE" 400
check "(h) ... as invalid_grant" "$(jq -r .error <<<"$RESP_BODY")" invalid_grant
# Reuse of a rotated token is theft evidence: the whole family is revoked.
split_resp "$(token_post -d grant_type=refresh_token -d "refresh_token=$REFRESH2" -d "client_id=$CLIENT_ID")"
check "(h) reuse detection revoked the family (newest refresh refused)" "$RESP_CODE" 400
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H "Authorization: Bearer $ACCESS2" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(h) ... and its access token now fails (401)" "$R" 401

# ================================================================ (i)
echo "== (i) RFC 7009 revocation"
new_pkce; V=$VERIFIER
do_consent "$JAR_ADMIN" "$CLIENT_ID" "read write" st-rev write || bad "(i) consent" "http=$CONSENT_CODE"
split_resp "$(token_post -d grant_type=authorization_code -d "code=$(qparam "$LOCATION" code)" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
RA=$(jq -r .access_token <<<"$RESP_BODY"); RR=$(jq -r .refresh_token <<<"$RESP_BODY")
R=$(call_tool "$RA" wp_site_info)
check "(i) fresh token works before revocation" "$(jq -r '.result.isError // false' <<<"$R")" false
split_resp "$(curl -s -w '\n%{http_code}' -X POST "$REV_EP" -d "token=$RR" -d token_type_hint=refresh_token -d "client_id=$CLIENT_ID")"
check "(i) revoke refresh token -> 200" "$RESP_CODE" 200
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H "Authorization: Bearer $RA" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(i) the access token then fails (401)" "$R" 401
split_resp "$(token_post -d grant_type=refresh_token -d "refresh_token=$RR" -d "client_id=$CLIENT_ID")"
check "(i) the revoked refresh token is refused" "$RESP_CODE" 400
split_resp "$(curl -s -w '\n%{http_code}' -X POST "$REV_EP" -d "token=never-issued-$(rand 8)" -d "client_id=$CLIENT_ID")"
check "(i) revoking an unknown token is still 200 (RFC 7009 s2.2)" "$RESP_CODE" 200
split_resp "$(curl -s -w '\n%{http_code}' -X POST "$REV_EP" -d "token=$ACCESS" -d "client_id=otherclientid$(rand 4)")"
check "(i) revocation with an unknown client_id is 401" "$RESP_CODE" 401
# Revoking an access token directly.
new_pkce; V=$VERIFIER
do_consent "$JAR_ADMIN" "$CLIENT_ID" "read" st-rev2 untick || bad "(i) consent 2" "http=$CONSENT_CODE"
split_resp "$(token_post -d grant_type=authorization_code -d "code=$(qparam "$LOCATION" code)" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
RA2=$(jq -r .access_token <<<"$RESP_BODY")
curl -s -o /dev/null -X POST "$REV_EP" -d "token=$RA2" -d token_type_hint=access_token -d "client_id=$CLIENT_ID"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H "Authorization: Bearer $RA2" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(i) a revoked access token fails (401)" "$R" 401

# ================================================================ (j)
echo "== (j) authorization-code replay"
new_pkce; V=$VERIFIER
do_consent "$JAR_ADMIN" "$CLIENT_ID" "read write" st-replay write || bad "(j) consent" "http=$CONSENT_CODE"
RCODE=$(qparam "$LOCATION" code)
split_resp "$(token_post -d grant_type=authorization_code -d "code=$RCODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
check "(j) first redemption -> 200" "$RESP_CODE" 200
JA=$(jq -r .access_token <<<"$RESP_BODY")
split_resp "$(token_post -d grant_type=authorization_code -d "code=$RCODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
check "(j) immediate second redemption of the same code -> 400" "$RESP_CODE" 400
check "(j) ... as invalid_grant" "$(jq -r .error <<<"$RESP_BODY")" invalid_grant
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H "Authorization: Bearer $JA" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(j) inside the 10s grace window the first token survives (double-submit safe)" "$R" 200
echo "     waiting out the replay grace window (REPLAY_GRACE_SECONDS=10)"
sleep 11
split_resp "$(token_post -d grant_type=authorization_code -d "code=$RCODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$V")"
check "(j) later replay is still invalid_grant" "$(jq -r .error <<<"$RESP_BODY")" invalid_grant
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$MCP" -H "Authorization: Bearer $JA" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "(j) replay after the grace window revokes the first redemption's tokens (RFC 6749 4.1.2)" "$R" 401
split_resp "$(token_post -d grant_type=authorization_code -d "code=$SPENT_CODE" -d "redirect_uri=$REDIRECT" -d "client_id=$CLIENT_ID" -d "code_verifier=$ADMIN_VERIFIER")"
check "(j) the code spent in step (e) is dead too" "$RESP_CODE" 400

# ---------------------------------------------------------------- result
echo
echo "== $pass passed, $fail failed"
if [[ $KEEP -eq 1 ]]; then
	if [[ -n $SITE_URL_OVERRIDE ]]; then
		wpcli option update home "$SITE_URL_OVERRIDE" >/dev/null && wpcli option update siteurl "$SITE_URL_OVERRIDE" >/dev/null
		SHOWN=$SITE_URL_OVERRIDE
	else
		SHOWN=$SITE
	fi
	HUMAN_PW=$(rand 16)
	wpcli user create e2ehuman e2e-human@example.invalid --role=administrator --user_pass="$HUMAN_PW" >/dev/null 2>&1
	echo
	echo "Stack kept for a joint manual test (containers $WP, $DB):"
	echo "  MCP URL : $SHOWN/wp-json/mcpwp/v1/mcp"
	echo "  Login   : $SHOWN/wp-login.php   user e2ehuman   password $HUMAN_PW"
	echo "  (printed once; this password is not stored anywhere)"
	[[ -z $SITE_URL_OVERRIDE ]] && echo "  Local only: claude.ai / ChatGPT need a public https URL. Tunnel to 127.0.0.1:$PORT, then --down and re-run with --keep --site-url https://<tunnel>."
	echo "  Remove  : bash $0 --down"
fi
[[ $fail -eq 0 ]] || exit 1
