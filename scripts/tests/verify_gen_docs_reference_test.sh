#!/usr/bin/env bash
# Contract + behaviour tests for scripts/gen-docs-reference.sh and .mjs.
#
# Static checks strip comment lines first. Behavioural checks run the real scripts against
# stubs: a fake `docker` and `node` for the shell (no containers), a mock MCP endpoint for
# `fetch`, and well-formed snapshot fixtures for `render`.
#
# --self-test first runs the suite against unmutated copies and fails unless it passes, then
# applies known-bad mutations to copies of the scripts and requires the suite to FAIL for each.
#
# Usage: bash scripts/tests/verify_gen_docs_reference_test.sh [--self-test]
# Exit:  0 contract holds, 1 it does not, 2 could not run

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF="$ROOT_DIR/scripts/tests/$(basename "${BASH_SOURCE[0]}")"
REAL_NODE="$(command -v node || true)"
[[ -n "$REAL_NODE" ]] || { echo "node required" >&2; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "python3 required" >&2; exit 2; }

FAKE_KEY="mcpwp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

# --- helpers --------------------------------------------------------------------------------

FAIL=0
ok() { echo "ok: $1"; }
bad() { echo "not ok: $1" >&2; FAIL=$((FAIL + 1)); }
expect() { # expect DESC CONDITION-EXIT-CODE
	if [[ "$2" -eq 0 ]]; then ok "$1"; else bad "$1"; fi
}
has() { grep -qF -- "$2" "$1"; } # file contains literal

# Build the stub layout: $1/scripts/{gen-docs-reference.sh,.mjs,release_smoke.sh}, $1/mcpwp,
# $1/bin/{docker,node}. $2 = directory holding the scripts under test.
make_layout() {
	local d="$1" src="$2"
	mkdir -p "$d/scripts" "$d/bin"
	cp "$src/gen-docs-reference.sh" "$src/gen-docs-reference.mjs" "$d/scripts/"
	ln -s "$ROOT_DIR/mcpwp" "$d/mcpwp"
	cat >"$d/scripts/release_smoke.sh" <<'STUB'
#!/usr/bin/env bash
echo "==> 16 passed, 0 failed"
exit 0
STUB
	cat >"$d/bin/docker" <<'STUB'
#!/usr/bin/env bash
echo "docker $*" >>"$STUB_LOG"
case "$*" in
	*wordpress:cli*)
		case "$*" in
			*"plugin is-active elementor-pro"*) [[ "${STUB_PRO:-0}" == 1 ]] && exit 0 || exit 1 ;;
			*"plugin is-active"*) exit 0 ;;
			*" eval "*)
				if [[ "${STUB_MINT_FAIL:-0}" == 1 ]]; then echo "GEN:ERROR:boom"; else echo "$STUB_KEY"; fi
				exit 0 ;;
			*"plugin list"*) echo "elementor woocommerce"; exit 0 ;;
		esac ;;
esac
exit 0
STUB
	cat >"$d/bin/node" <<'STUB'
#!/usr/bin/env bash
# $1 = script, $2 = fetch|render
echo "node $*" >>"$STUB_LOG"
if [[ "$2" == fetch ]]; then
	# The key must reach fetch through the environment (recorded apart from $STUB_LOG, which
	# must never hold it), so the "key not in the log" check below is not vacuous.
	[[ -n "${MCPWP_API_KEY:-}" && "${MCPWP_API_KEY:-}" == "$STUB_KEY" ]] && echo "fetch got the key from env" >>"$STUB_ENV_LOG"
	ed=""; snap=""
	while [[ $# -gt 0 ]]; do
		case "$1" in --edition) ed="$2" ;; --snapshot) snap="$2" ;; esac
		shift
	done
	"$REAL_NODE" -e '
const fs = require("fs");
const s = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
s.elementor_pro = process.env.MCPWP_ELEMENTOR_PRO === "1";
fs.writeFileSync(process.argv[2], JSON.stringify(s));' "$STUB_FIXTURES/$ed.json" "$snap"
	echo "$ed: stub snapshot"
	exit 0
fi
exec "$REAL_NODE" "$@"
STUB
	chmod +x "$d/bin/docker" "$d/bin/node" "$d/scripts/release_smoke.sh"
}

# Well-formed snapshots built from the real plugin source: the Pro snapshot carries every tool
# the source says is Elementor-Pro-gated, `pro_missing` lacks them but claims elementor_pro.
make_fixtures() {
	local d="$1"
	mkdir -p "$d"
	"$REAL_NODE" - "$ROOT_DIR/mcpwp" "$d" <<'JS'
const fs = require('fs');
const [plugin, out] = process.argv.slice(2);
const names = new Set();
const proGated = [];
for (const f of ['includes/mcp/class-mcpwp-mcp-free-tools.php', 'includes/mcp/class-mcpwp-mcp-pro-tools.php']) {
  const src = fs.readFileSync(`${plugin}/${f}`, 'utf8');
  for (const m of src.matchAll(/'([a-z_]+)'\s*=>\s*'elementor_pro'/g)) proGated.push(m[1]);
}
const tool = (name) => ({
  name, title: name, description: `Fixture tool ${name}.`,
  inputSchema: { type: 'object', properties: {} },
  annotations: { readOnlyHint: true, category: 'content' }, category: 'content', route: null,
});
const base = ['wp_site_info', 'wp_list_pages', 'wp_flush_cache'].map(tool);
const snap = (edition, tools, extra = {}) => ({
  edition, source: 'introspect', plugin_version: '', fetched_at: 'x', tool_count: tools.length, tools,
  resources: [], resource_templates: [], prompts: [], elementor_pro: false, ...extra,
});
const pro = [...base, tool('wp_fixture_pro_only'), ...proGated.map(tool)];
fs.writeFileSync(`${out}/free.json`, JSON.stringify(snap('free', base)));
fs.writeFileSync(`${out}/pro.json`, JSON.stringify(snap('pro', pro)));
fs.writeFileSync(`${out}/pro_missing.json`, JSON.stringify(snap('pro', [...base, tool('wp_fixture_pro_only')], { elementor_pro: true })));
fs.writeFileSync(`${out}/pro_names.txt`, proGated.join('\n') + '\n');
JS
}

# The tool names the plugin shows every key whatever its role or scope, read straight from
# get_key_exempt_tool_names() (independently of the generator's own parser). A real site lists
# them for every key, so every mock catalog below carries them too.
meta_tool_names() {
	"$REAL_NODE" -e '
const src = require("fs").readFileSync(process.argv[1], "utf8");
const m = /function\s+get_key_exempt_tool_names\s*\(\s*\)\s*\{([\s\S]*?)\}/.exec(src);
if (!m) process.exit(1);
process.stdout.write([...m[1].matchAll(/\x27([a-z_]+)\x27/g)].map((x) => x[1]).join(","));' "$ROOT_DIR/mcpwp/includes/traits/trait-mcpwp-api-auth.php"
}

# Mock MCP endpoint. MODE: unauthorized | status | empty | metaonly | narrow | rolenarrow | full.
# Writes its port to $1/port.
#   metaonly    only the key-exempt meta tools (what a deny-all key sees)
#   narrow      a read-only key: discovery + read tools + meta tools, no admin-scope tool
#   rolenarrow  an editor/designer key with admin scope: admin-scope tools from its own
#               categories (wp_list_content, wp_flush_cache), none from the admin category
#   full        an admin-role, admin-scope key
start_mock() {
	local d="$1" mode="$2"
	cat >"$d/mock.js" <<'JS'
const http = require('http');
const mode = process.env.MODE;
const tool = (name) => ({ name, title: name, description: 'd', inputSchema: { type: 'object', properties: {} }, annotations: { category: 'content' } });
const meta = process.env.META_TOOLS.split(',').filter(Boolean).map(tool);
const narrow = [...['wp_site_info', 'wp_list_pages'].map(tool), ...meta];
const rolenarrow = [...narrow, tool('wp_list_content'), tool('wp_flush_cache')];
const full = [...rolenarrow, tool('wp_list_api_keys')];
http.createServer((req, res) => {
  let b = '';
  req.on('data', (c) => (b += c));
  req.on('end', () => {
    const q = JSON.parse(b || '{}');
    const send = (code, o) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
    if (mode === 'status') return send(503, { jsonrpc: '2.0', id: q.id, result: { tools: full } });
    if (mode === 'unauthorized') return send(401, { code: 'invalid_api_key', message: 'Invalid API key.', data: { status: 401 } });
    const tools = { full, rolenarrow, metaonly: meta }[mode] || narrow;
    const ok = (result) => send(200, { jsonrpc: '2.0', id: q.id, result });
    switch (q.method) {
      case 'initialize': return ok({ serverInfo: { name: 'm', plugin_version: '0.0.0' } });
      case 'tools/list': return ok(mode === 'empty' ? {} : { tools });
      case 'tools/call': return ok({ content: [{ type: 'text', text: JSON.stringify({ tools }) }] });
      case 'resources/list': case 'resources/templates/list': case 'prompts/list': return send(200, { code: 'rest_forbidden', message: 'no' });
      default: return ok({});
    }
  });
}).listen(0, '127.0.0.1', function () { require('fs').writeFileSync(process.env.PORT_FILE, String(this.address().port)); });
JS
	MODE="$mode" META_TOOLS="$META_TOOLS" PORT_FILE="$d/port" "$REAL_NODE" "$d/mock.js" >/dev/null 2>&1 &
	MOCK_PID=$!
	for _ in $(seq 1 50); do [[ -s "$d/port" ]] && break; sleep 0.1; done
}

# --- the suite ------------------------------------------------------------------------------
# run_suite SRC_DIR : every check against the scripts in SRC_DIR. Increments FAIL.
run_suite() {
	local src="$1" T
	T="$(mktemp -d)"
	local SH="$src/gen-docs-reference.sh" MJS="$src/gen-docs-reference.mjs"

	# Plain files, not pipes: `grep -q` under pipefail would turn a match into SIGPIPE.
	grep -v '^[[:space:]]*#' "$SH" >"$T/sh"
	grep -v '^[[:space:]]*//' "$MJS" >"$T/mjs"

	# 1. static: the shared rig, the legacy key, the scoped creator
	grep -Eq 'wp-rig|test-rig|docker cp' "$T/sh" && bad "sh references a rig or docker cp" || ok "sh has no rig reference or docker cp"
	grep -Eq 'wp-rig|test-rig' "$T/mjs" && bad "mjs references a rig" || ok "mjs has no rig reference"
	grep -Eq 'update_option|delete_option|mcpwp_api_key' "$T/sh" && bad "sh touches the legacy key option" || ok "sh never touches the legacy key option"
	has "$T/sh" 'create_scoped_api_key' && ok "sh mints through create_scoped_api_key" || bad "sh does not use create_scoped_api_key"
	has "$T/sh" 'array( "read", "write", "admin" ), "admin" )' && ok "sh requests EXACTLY read,write,admin scopes with the admin role" || bad "sh scope list is not exactly read,write,admin / admin"

	# 2. layout for the behavioural checks
	make_fixtures "$T/fx"
	local L="$T/layout"
	make_layout "$L" "$src"
	local OUT="$T/out" LOG="$T/log" ENVLOG="$T/envlog"
	export STUB_LOG="$LOG" STUB_ENV_LOG="$ENVLOG" STUB_KEY="$FAKE_KEY" STUB_FIXTURES="$T/fx" REAL_NODE

	# key_never_leaks LABEL : after a run_sh, the key is nowhere in what the run left behind
	key_never_leaks() {
		grep -qF -- "$FAKE_KEY" "$T/so" "$T/se" && bad "$1: the API key appears in the script's stdout/stderr" || ok "$1: the API key never appears in stdout/stderr"
		grep -qF -- "$FAKE_KEY" "$LOG" && bad "$1: the API key appears in a logged docker/node command line" || ok "$1: the API key never appears in a logged command line"
		grep -rqF -- "$FAKE_KEY" "$OUT" && bad "$1: the API key appears in a file under \$OUT" || ok "$1: the API key never appears in a file under \$OUT"
		[[ "$(grep -c 'fetch got the key from env' "$ENVLOG" 2>/dev/null || true)" -eq 2 ]] && ok "$1: both fetches got the key from the environment (the leak checks are not vacuous)" || bad "$1: the fetches did not get the key from the environment"
	}

	run_sh() { # run_sh STUB_PRO [env...]  -> stdout $T/so, stderr $T/se, rc in $RC
		local pro="$1"; shift
		rm -rf "$OUT"; mkdir -p "$OUT"; : >"$LOG"; : >"$ENVLOG"
		env PATH="$L/bin:$PATH" STUB_PRO="$pro" "$@" bash "$L/scripts/gen-docs-reference.sh" \
			--candidate "$ROOT_DIR/scripts/tests/$(basename "$SELF")" --out "$OUT" --port 1 --name gf-test >"$T/so" 2>"$T/se"
		RC=$?
	}

	# D. no Elementor Pro: loud warning, refusal, fail fast, nothing written
	run_sh 0
	[[ $RC -eq 1 ]] && ok "no Pro: exit 1" || bad "no Pro: exit $RC, expected 1"
	has "$T/se" 'will be MISSING' && has "$T/se" 'NOT a complete reference' && ok "no Pro: warning reaches stderr" || bad "no Pro: warning missing from stderr"
	has "$T/se" 'Refusing' && ok "no Pro: refusal message" || bad "no Pro: no refusal message"
	[[ -z "$(ls -A "$OUT")" ]] && ok "no Pro: out dir stays empty" || bad "no Pro: out dir was written"
	grep -q 'node .* fetch' "$LOG" && bad "no Pro: fetch ran before the refusal (not fail-fast)" || ok "no Pro: refused before any fetch"

	# E. GEN_ALLOW_INCOMPLETE=1 renders, labelled
	run_sh 0 GEN_ALLOW_INCOMPLETE=1
	[[ $RC -eq 0 ]] && ok "allow-incomplete: exit 0" || bad "allow-incomplete: exit $RC (stderr: $(tail -2 "$T/se" | tr '\n' ' '))"
	has "$T/se" 'will be MISSING' && ok "allow-incomplete: warning still printed" || bad "allow-incomplete: warning missing"
	has "$OUT/tools.md" 'INCOMPLETE reference' && has "$OUT/reference/endpoints.md" 'INCOMPLETE reference' && ok "allow-incomplete: banner on tools.md and endpoints.md" || bad "allow-incomplete: banner missing"
	local missing_banner=0 f
	for f in "$OUT"/tools/*.md; do has "$f" 'INCOMPLETE reference' || missing_banner=1; done
	[[ $missing_banner -eq 0 ]] && ok "allow-incomplete: banner on every tools/*.md" || bad "allow-incomplete: a tools/*.md has no banner"
	has "$T/so" 'Done, but the output is INCOMPLETE' && ! has "$T/so" 'Review the diff' && ok "allow-incomplete: final line says INCOMPLETE" || bad "allow-incomplete: final line does not say INCOMPLETE"
	key_never_leaks "allow-incomplete"

	# F. Pro present: no --allow-incomplete, no banner, normal final line
	run_sh 1
	[[ $RC -eq 0 ]] && ok "with Pro: exit 0" || bad "with Pro: exit $RC (stderr: $(tail -2 "$T/se" | tr '\n' ' '))"
	grep -q 'allow-incomplete' "$LOG" && bad "with Pro: render was passed --allow-incomplete" || ok "with Pro: render not passed --allow-incomplete"
	grep -rq 'INCOMPLETE' "$OUT" && bad "with Pro: output carries an INCOMPLETE banner" || ok "with Pro: output has no banner"
	has "$T/so" 'Review the diff' && ok "with Pro: normal final line" || bad "with Pro: no normal final line"

	# G. key handling and memory flag (use the log of run F)
	key_never_leaks "with Pro"
	grep -q 'array( "read", "write", "admin" ), "admin" )' "$LOG" && ok "the eval sent to wp-cli asks for read,write,admin / admin" || bad "the eval sent to wp-cli has the wrong scopes"
	local nomem
	nomem="$(grep -E 'wordpress:cli| wp ' "$LOG" | grep -vc 'memory_limit=' || true)"
	[[ "$nomem" -eq 0 ]] && ok "every wp call carries php -d memory_limit" || bad "$nomem wp call(s) lack php -d memory_limit"
	grep -q 'wordpress:cli' "$LOG" && ok "the stub saw wp-cli calls (the memory check is not vacuous)" || bad "no wp-cli call observed"

	# H. mint failure shows the diagnostic, not the key
	run_sh 1 STUB_MINT_FAIL=1
	[[ $RC -eq 2 ]] && has "$T/se" 'GEN:ERROR:boom' && ok "mint failure: exit 2 and the GEN:ERROR diagnostic is shown" || bad "mint failure: rc=$RC, diagnostic hidden"

	# I. render against fixtures
	local R="$L/scripts/gen-docs-reference.mjs"
	rm -rf "$OUT"; mkdir -p "$OUT"
	"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro_missing.json" --out "$OUT" >"$T/so" 2>"$T/se"; RC=$?
	[[ $RC -eq 1 ]] && ok "render: a forged elementor_pro:true without the Pro tools exits 1" || bad "render: forged flag exit $RC, expected 1"
	grep -q '^gen-docs-reference: refusing' "$T/se" && ok "render: refusal prefix, not a crash" || bad "render: no 'gen-docs-reference: refusing' ($(tail -1 "$T/se"))"
	[[ -z "$(ls -A "$OUT")" ]] && ok "render: refusal writes nothing" || bad "render: refusal wrote files"
	local first_pro
	first_pro="$(head -1 "$T/fx/pro_names.txt")"
	has "$T/se" "$first_pro" && ok "render: the warning names the missing Pro tools" || bad "render: warning does not name the missing tools"

	rm -rf "$OUT"; mkdir -p "$OUT"
	"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro_missing.json" --out "$OUT" --allow-incomplete >"$T/so" 2>"$T/se"; RC=$?
	[[ $RC -eq 0 ]] && has "$OUT/tools.md" 'INCOMPLETE reference' && has "$OUT/reference/endpoints.md" 'INCOMPLETE reference' && ok "render --allow-incomplete: exit 0 with banners" || bad "render --allow-incomplete: rc=$RC"

	# The docs tree mixes generated and hand-written pages in tools/ and reference/. The
	# INCOMPLETE banner goes on generated pages only, exactly once, however often render runs.
	rm -rf "$OUT"; mkdir -p "$OUT/tools" "$OUT/reference" "$T/hand"
	local hw
	for hw in reference/auth.md reference/rate-limits.md tools/approvals.md tools/menus.md; do
		printf -- '---\ntitle: Hand-written %s\n---\n\nWritten by a person; the generator must not touch it.\n' "$hw" >"$OUT/$hw"
		mkdir -p "$T/hand/$(dirname "$hw")"; cp "$OUT/$hw" "$T/hand/$hw"
	done
	# A generated page left by an earlier incomplete run, render does not rewrite it this time.
	printf -- '---\ntitle: Old\n---\n\n:::caution[INCOMPLETE reference]\nold\n:::\n\n<!-- GENERATED by scripts/gen-docs-reference.mjs in Digidinc/mcpwp from MCPWP 0. -->\n' >"$OUT/reference/old-generated.md"
	local pass
	for pass in 1 2; do
		"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro_missing.json" --out "$OUT" --allow-incomplete >"$T/so" 2>"$T/se"; RC=$?
		[[ $RC -eq 0 ]] && ok "render --allow-incomplete pass $pass: exit 0 with hand-written pages present" || bad "render --allow-incomplete pass $pass: rc=$RC ($(tail -1 "$T/se"))"
		local untouched=1
		for hw in reference/auth.md reference/rate-limits.md tools/approvals.md tools/menus.md; do
			cmp -s "$OUT/$hw" "$T/hand/$hw" || { untouched=0; bad "render pass $pass: hand-written $hw was modified"; }
		done
		[[ $untouched -eq 1 ]] && ok "render pass $pass: every hand-written page is byte-identical"
		local once=1 n
		for f in "$OUT/tools.md" "$OUT"/tools/*.md "$OUT"/reference/*.md; do
			[[ -f "$T/hand/${f#"$OUT"/}" ]] && continue
			n="$(grep -c 'INCOMPLETE reference' "$f" || true)"
			[[ "$n" -eq 1 ]] || { once=0; bad "render pass $pass: ${f#"$OUT"/} carries $n INCOMPLETE banners, expected 1"; }
		done
		[[ $once -eq 1 ]] && ok "render pass $pass: every generated page carries exactly one banner"
	done

	rm -rf "$OUT"; mkdir -p "$OUT"
	"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro.json" --out "$OUT" >"$T/so" 2>"$T/se"; RC=$?
	[[ $RC -eq 1 ]] && ok "render: a snapshot whose flag is false is refused even with every Pro tool present" || bad "render: false flag exit $RC"
	"$REAL_NODE" -e '
const fs=require("fs");const p=process.argv[1];const s=JSON.parse(fs.readFileSync(p));s.elementor_pro=true;fs.writeFileSync(p,JSON.stringify(s));' "$T/fx/pro.json"
	rm -rf "$OUT"; mkdir -p "$OUT"
	"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro.json" --out "$OUT" >"$T/so" 2>"$T/se"; RC=$?
	[[ $RC -eq 0 ]] && ! grep -rq 'INCOMPLETE' "$OUT" && ok "render: a complete Pro snapshot renders with no banner" || bad "render: complete snapshot rc=$RC ($(tail -1 "$T/se"))"
	# forged tool_count cannot bypass the Pro-has-more-tools check: every Pro tool is present and the
	# flag is true, so only the real tool counts can refuse this (Free is padded past Pro).
	"$REAL_NODE" -e '
const fs=require("fs");const d=process.argv[1];
const pro=JSON.parse(fs.readFileSync(d+"/pro.json"));const free=JSON.parse(fs.readFileSync(d+"/free.json"));
pro.tool_count=999;
free.tools=[...pro.tools,...pro.tools.map((t)=>({...t,name:t.name+"_pad"}))];free.tool_count=1;
fs.writeFileSync(d+"/pro.json",JSON.stringify(pro));fs.writeFileSync(d+"/free.json",JSON.stringify(free));' "$T/fx"
	rm -rf "$OUT"; mkdir -p "$OUT"
	"$REAL_NODE" "$R" render --free "$T/fx/free.json" --pro "$T/fx/pro.json" --out "$OUT" >"$T/so" 2>"$T/se"; RC=$?
	[[ $RC -ne 0 && -z "$(ls -A "$OUT")" ]] && has "$T/se" 'has no more tools than Free' && ok "render: a forged tool_count does not bypass the tool-count check" || bad "render: forged tool_count accepted (rc=$RC)"

	# J. fetch against a mock endpoint
	local mode port snap="$T/snap.json" plug="$ROOT_DIR/mcpwp"
	META_TOOLS="$(meta_tool_names)"
	[[ ",$META_TOOLS," == *",mcpwp_call,"* && ",$META_TOOLS," == *",mcpwp_find_tools,"* ]] && ok "the plugin's key-exempt tools ($META_TOOLS) include mcpwp_call and mcpwp_find_tools" || bad "could not read the key-exempt tools from the plugin (got '$META_TOOLS')"
	# A plugin tree whose get_key_exempt_tool_names() the generator cannot find: symlinks to the
	# real files, except the auth trait, copied with the function renamed.
	local broken="$T/plugin-noexempt"
	cp -rs "$ROOT_DIR/mcpwp/." "$broken" 2>/dev/null
	rm -f "$broken/includes/traits/trait-mcpwp-api-auth.php"
	sed 's/function get_key_exempt_tool_names/function renamed_exempt_tool_names/' "$ROOT_DIR/mcpwp/includes/traits/trait-mcpwp-api-auth.php" >"$broken/includes/traits/trait-mcpwp-api-auth.php"
	for mode in unauthorized status empty metaonly narrow rolenarrow full noexempt; do
		rm -f "$T/port" "$snap"
		plug="$ROOT_DIR/mcpwp"
		if [[ "$mode" == noexempt ]]; then start_mock "$T" full; plug="$broken"; else start_mock "$T" "$mode"; fi
		port="$(cat "$T/port")"
		MCPWP_SITE_URL="http://127.0.0.1:$port" MCPWP_API_KEY="$FAKE_KEY" "$REAL_NODE" "$R" fetch --edition free --plugin-dir "$plug" --snapshot "$snap" >"$T/so" 2>"$T/se"; RC=$?
		kill "$MOCK_PID" 2>/dev/null; wait "$MOCK_PID" 2>/dev/null
		case "$mode" in
			unauthorized)
				[[ $RC -eq 2 ]] && has "$T/se" 'HTTP 401' && has "$T/se" 'invalid_api_key' && ok "fetch: HTTP 401 invalid_api_key is reported as such" || bad "fetch/401: rc=$RC $(tail -1 "$T/se")" ;;
			status)
				[[ $RC -eq 2 ]] && has "$T/se" 'HTTP 503' && ok "fetch: a non-200 status is a failure even with a result body" || bad "fetch/503: rc=$RC $(tail -1 "$T/se")" ;;
			empty)
				[[ $RC -eq 2 ]] && has "$T/se" 'returned no tools and no error' && ok "fetch: an empty tools/list result is a clear failure" || bad "fetch/empty: rc=$RC $(tail -1 "$T/se")" ;;
			metaonly)
				[[ $RC -eq 2 ]] && has "$T/se" "scope is narrower than admin" && [[ ! -e "$snap" ]] && ok "fetch: a catalog of only the meta tools (mcpwp_call is in \$admin_tools) is refused" || bad "fetch/metaonly: rc=$RC $(tail -1 "$T/se")" ;;
			narrow)
				[[ $RC -eq 2 ]] && has "$T/se" "scope is narrower than admin" && [[ ! -e "$snap" ]] && ok "fetch: a read-only catalog (meta tools included) is refused" || bad "fetch/narrow: rc=$RC $(tail -1 "$T/se")" ;;
			rolenarrow)
				[[ $RC -eq 2 ]] && has "$T/se" "role is narrower than admin" && [[ ! -e "$snap" ]] && ok "fetch: an editor/designer catalog with admin scope but no admin-category tool is refused" || bad "fetch/rolenarrow: rc=$RC $(tail -1 "$T/se")" ;;
			full)
				[[ $RC -eq 0 && -s "$snap" ]] && ! grep -qF -- "$FAKE_KEY" "$snap" && grep -q '"mcpwp_call"' "$snap" && ok "fetch: a full catalog is written, meta tools included, without the key" || bad "fetch/full: rc=$RC $(tail -1 "$T/se")" ;;
			noexempt)
				[[ $RC -eq 2 ]] && has "$T/se" 'could not parse get_key_exempt_tool_names' && [[ ! -e "$snap" ]] && ok "fetch: an unparseable key-exempt list fails closed" || bad "fetch/noexempt: rc=$RC $(tail -1 "$T/se")" ;;
		esac
	done

	rm -rf "$T"
}

# --- self-test: mutations that must each break the suite ------------------------------------
self_test() {
	local M="$(mktemp -d)" n=0 survived=0
	# A suite that already fails "kills" every mutation; the results would mean nothing.
	# Run it unmutated first, on a copy laid out exactly like the mutants.
	local base nbad nok
	mkdir -p "$M/base"
	cp "$ROOT_DIR/scripts/gen-docs-reference.sh" "$ROOT_DIR/scripts/gen-docs-reference.mjs" "$M/base/"
	base="$(FAIL=0; run_suite "$M/base" 2>&1)"
	nbad="$(grep -c '^not ok' <<<"$base" || true)"
	nok="$(grep -c '^ok: ' <<<"$base" || true)"
	if [[ "$nbad" -ne 0 || "$nok" -eq 0 ]]; then
		grep '^not ok' <<<"$base" >&2
		echo "self-test: the unmutated suite does not pass ($nbad failing, $nok passing); not running mutations" >&2
		rm -rf "$M"
		return 1
	fi
	echo "baseline: the unmutated suite passes ($nok checks)"
	mutate() { # mutate NAME FILE OLD NEW
		local name="$1" file="$2" old="$3" new="$4" d="$M/m$((++n))"
		mkdir -p "$d"
		cp "$ROOT_DIR/scripts/gen-docs-reference.sh" "$ROOT_DIR/scripts/gen-docs-reference.mjs" "$d/"
		python3 - "$d/$file" "$old" "$new" <<'PY' || { echo "mutation '$name' did not apply" >&2; exit 2; }
import sys
p, old, new = sys.argv[1:4]
s = open(p).read()
if old not in s:
    sys.exit(1)
open(p, "w").write(s.replace(old, new, 1))
PY
		local out
		out="$(FAIL=0; run_suite "$d" 2>&1 >/dev/null | grep -c '^not ok' )"
		if [[ "${out:-0}" -gt 0 ]]; then
			echo "killed:   $name ($out failing checks)"
		else
			echo "SURVIVED: $name" >&2; survived=$((survived + 1))
		fi
	}
	local SHF=gen-docs-reference.sh MJF=gen-docs-reference.mjs
	mutate "shell refusal exits 0"                      $SHF 'to render pages labelled INCOMPLETE." >&2
		exit 1' 'to render pages labelled INCOMPLETE." >&2
		exit 0'
	mutate "shell always passes --allow-incomplete"     $SHF '[[ "$PRO_ACTIVE" -ne 1 ]] && RENDER_FLAGS+=(--allow-incomplete)' 'RENDER_FLAGS+=(--allow-incomplete)'
	mutate "warning sent to /dev/null"                  $SHF 'will be MISSING from the snapshot; the output is NOT a complete reference." >&2' 'will be MISSING from the snapshot; the output is NOT a complete reference." >/dev/null'
	mutate "warning under if false"                     $SHF 'if [[ "$PRO_ACTIVE" -ne 1 ]]; then
	echo "!!! WARNING' 'if false; then
	echo "!!! WARNING'
	mutate "PRO_ACTIVE hard-coded to 1"                 $SHF 'wpcli plugin is-active elementor-pro >/dev/null 2>&1 && PRO_ACTIVE=1' 'PRO_ACTIVE=1'
	mutate "banner write removed (mjs)"                 $MJF 'writeFileSync(f, m ? m[0]' 'void (m ? m[0]'
	mutate "empty-list guard disabled"                  $MJF "if (!listResult || !Array.isArray(listResult.tools) || !listResult.tools.length) {" "if (false) {"
	mutate "narrowed scopes (read,write)"               $SHF 'array( "read", "write", "admin" ), "admin" )' 'array( "read", "write" ), "admin" )'
	mutate "wp call without a memory flag"              $SHF 'docker exec "$WP" rm -f "$MARKER"

echo "==> Free catalog"' 'docker exec "$WP" wp plugin list
docker exec "$WP" rm -f "$MARKER"

echo "==> Free catalog"'
	mutate "key echoed to the log"                      $SHF 'KEY="$(grep -E' 'echo "using key $MINT_OUT"; KEY="$(grep -E'
	mutate "mjs refusal downgraded to a warning"        $MJF "if (!allowIncomplete) die('refusing to render an incomplete reference" "if (false) die('refusing to render an incomplete reference"
	mutate "completeness trusts the self-reported flag" $MJF "const incomplete = missingPro.length > 0 || pro.elementor_pro !== true;" "const incomplete = pro.elementor_pro !== true;"
	mutate "forged tool_count accepted"                 $MJF "if (nPro <= nFree || pro.tool_count <= free.tool_count)" "if (pro.tool_count <= free.tool_count)"
	mutate "admin-tool floor removed"                   $MJF "if (!adminSeen.length) {" "if (false) {"
	mutate "HTTP error status ignored"                  $MJF "if (!res.ok) {" "if (false) {"
	mutate "meta tools counted toward the admin floor"  $MJF "const everyKey = new Set([...facts.keyExempt, ...facts.discovery]);" "const everyKey = new Set();"
	mutate "admin-category (role) floor removed"        $MJF "if (!roleFloor.some((n) => listedNames.has(n))) {" "if (false) {"
	mutate "unparseable key-exempt list tolerated"      $MJF "if (!keyExempt.length) die('could not parse get_key_exempt_tool_names()');" ""
	mutate "banner on hand-written pages"               $MJF "if (!body.includes(GENERATED_MARKER) || body.includes(bannerHead)) continue;" "if (body.includes(bannerHead)) continue;"
	mutate "banner not idempotent"                      $MJF "if (!body.includes(GENERATED_MARKER) || body.includes(bannerHead)) continue;" "if (!body.includes(GENERATED_MARKER)) continue;"
	mutate "key passed on the node command line"        $SHF 'node "$HERE/gen-docs-reference.mjs" fetch --edition free' 'node "$HERE/gen-docs-reference.mjs" fetch --key "$KEY" --edition free'
	mutate "key written under the output dir"          $SHF 'echo "==> Render into $OUT"' 'echo "==> Render into $OUT"; echo "$KEY" >"$OUT/.k"'
	mutate "key no longer passed through the env"       $SHF 'MCPWP_SITE_URL="$URL" MCPWP_API_KEY="$KEY" node "$HERE/gen-docs-reference.mjs" fetch --edition free' 'MCPWP_SITE_URL="$URL" node "$HERE/gen-docs-reference.mjs" fetch --edition free'
	rm -rf "$M"
	[[ $survived -eq 0 ]] && { echo "self-test: every mutation was caught"; return 0; }
	echo "self-test: $survived mutation(s) survived" >&2
	return 1
}

if [[ "${1:-}" == "--self-test" ]]; then
	self_test
	exit $?
fi

run_suite "$ROOT_DIR/scripts"
if [[ $FAIL -eq 0 ]]; then echo "all passed"; else echo "FAILED ($FAIL)" >&2; exit 1; fi
