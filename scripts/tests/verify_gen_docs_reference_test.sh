#!/usr/bin/env bash
# Static contract tests for scripts/gen-docs-reference.sh and .mjs.
# Comment lines are stripped before grepping so header text cannot satisfy an assertion.
# Usage: bash scripts/tests/verify_gen_docs_reference_test.sh
# Exit:  0 contract holds, 1 it does not

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SH="$ROOT/scripts/gen-docs-reference.sh"
MJS="$ROOT/scripts/gen-docs-reference.mjs"
FAIL=0
ok() { echo "ok: $1"; }
bad() { echo "not ok: $1" >&2; FAIL=1; }

# Plain files, not pipes: `grep -q` under pipefail would turn a match into a SIGPIPE failure.
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
grep -v '^[[:space:]]*#' "$SH" > "$TMP/sh"
grep -v '^[[:space:]]*//' "$MJS" > "$TMP/mjs"
code_sh() { cat "$TMP/sh"; }
code_mjs() { cat "$TMP/mjs"; }

# 1. never reads the shared rig
if grep -Eq 'wp-rig|test-rig|docker cp' "$TMP/sh"; then bad "sh references a rig or docker cp"; else ok "sh has no rig reference or docker cp"; fi
if grep -Eq 'wp-rig|test-rig' "$TMP/mjs"; then bad "mjs references a rig"; else ok "mjs has no rig reference"; fi

# 2. no legacy single-key option
if grep -Eq "update_option|delete_option|mcpwp_api_key" "$TMP/sh"; then bad "sh writes the legacy key option"; else ok "sh never touches the legacy key option"; fi
grep -q 'create_scoped_api_key' "$TMP/sh" && ok "sh mints a key through create_scoped_api_key" || bad "sh does not use create_scoped_api_key"
grep -Eq '"admin"[[:space:]]*\)' "$TMP/sh" && ok "sh requests the admin role" || bad "sh does not request the admin role"

# 3. memory flag on every wp-cli invocation (the only direct wp call is inside wpcli())
if [[ "$(grep -c 'wordpress:cli' "$TMP/sh")" -eq 1 ]] && grep 'wordpress:cli' "$TMP/sh" | grep -q 'php -d "memory_limit='; then
	ok "the single wp-cli entry point sets memory_limit before WordPress boots"
else
	bad "a wordpress:cli call lacks php -d memory_limit"
fi

# 4. Elementor Pro comes only from an explicit outside copy, and its absence is loud
grep -q 'GEN_ELEMENTOR_PRO_DIR' "$TMP/sh" && ok "sh supports GEN_ELEMENTOR_PRO_DIR" || bad "no GEN_ELEMENTOR_PRO_DIR"
grep -q 'will be MISSING' "$TMP/sh" && grep -q 'NOT a complete reference' "$TMP/sh" && ok "sh warns loudly without Elementor Pro" || bad "no loud Pro warning"
grep -q 'Refusing to render' "$TMP/sh" && ok "sh refuses to render without Pro" || bad "sh does not refuse render"
grep -q "refusing to render an incomplete reference" "$TMP/mjs" && ok "render refuses an incomplete snapshot" || bad "mjs render does not refuse"
grep -q 'INCOMPLETE reference' "$TMP/mjs" && ok "render labels --allow-incomplete output" || bad "no INCOMPLETE label"

# 5. an empty tools/list is a clear failure, not a TypeError
grep -q 'returned no tools and no error' "$TMP/mjs" && ok "fetch fails clearly on an empty tools/list" || bad "empty tools/list not handled"

# 6. behavioural: render refuses a snapshot without elementor_pro
if command -v node >/dev/null 2>&1; then
	T="$(mktemp -d)"
	printf '{"edition":"free","tool_count":1,"tools":[]}' > "$T/f.json"
	printf '{"edition":"pro","tool_count":2,"tools":[]}' > "$T/p.json"
	mkdir -p "$T/out"
	node "$MJS" render --free "$T/f.json" --pro "$T/p.json" --out "$T/out" >/dev/null 2>"$T/err"; rc=$?
	if [[ $rc -eq 1 ]] && grep -q 'refusing to render an incomplete reference' "$T/err"; then ok "render exits 1 on a Pro-less snapshot"; else bad "render rc=$rc on a Pro-less snapshot"; fi
	rm -rf "$T"
fi

[[ $FAIL -eq 0 ]] && echo "all passed" || { echo "FAILED" >&2; exit 1; }
