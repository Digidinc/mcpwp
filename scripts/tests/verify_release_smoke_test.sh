#!/usr/bin/env bash
# Contract + regression tests for scripts/release_smoke.sh (issue #1241).
#
# Static checks strip comment lines before grepping so header comments cannot
# satisfy assertions. --self-test applies known-bad mutations and requires each
# to fail the checker.
#
# Optional behavioural checks (docker required, network for baseline download):
#   RELEASE_SMOKE_BEHAVIOUR=1 bash scripts/tests/verify_release_smoke_test.sh
#
# Usage: bash scripts/tests/verify_release_smoke_test.sh [--self-test]
# Exit:  0 contract holds · 1 it does not · 2 could not check

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF_PATH="$ROOT_DIR/scripts/tests/$(basename "${BASH_SOURCE[0]}")"
SMOKE_DEFAULT="$ROOT_DIR/scripts/release_smoke.sh"

PASS=0
FAIL=0

fail() {
	FAIL=$((FAIL + 1))
	echo "not ok: $*" >&2
}

pass() {
	PASS=$((PASS + 1))
	echo "ok: $*"
}

[[ -f "$SMOKE_DEFAULT" ]] || {
	echo "FAIL: missing $SMOKE_DEFAULT" >&2
	exit 2
}

command -v python3 >/dev/null 2>&1 || {
	echo "FAIL: python3 required" >&2
	exit 2
}

run_static_checker() {
	local script_path="${1:-$SMOKE_DEFAULT}"
	python3 - "$script_path" <<'PY'
import re
import sys

path = sys.argv[1]
raw = open(path, encoding="utf-8").read()
problems = []

def code_lines(text):
    out = []
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("#"):
            continue
        # strip trailing # comments (not inside quotes — good enough for this script)
        line = re.sub(r"\s+#.*$", "", line)
        if line.strip():
            out.append(line)
    return "\n".join(out)

code = code_lines(raw)

# --- exit code contract (in executable code, not comments) ---
for needle in (
    r"^#   0  every check ran and passed",
    r"^#   1  the site came up",
    r"^#   2  could not run",
):
    if not re.search(needle, raw, re.M):
        problems.append(f"exit code contract line missing in header: {needle}")

for line in ("exit 0", "exit 1", "exit 2"):
    if line not in code:
        problems.append(f"missing executable {line}")

# --- wp-rig ---
if re.search(r"wp-rig-wp-dev|docker\s+exec\s+wp-rig", code):
    problems.append("references wp-rig containers in code")

# --- legacy key store wipe / seeding ---
if re.search(r"delete_option\s*\(\s*['\"]mcpwp_api_keys['\"]", code):
    problems.append("delete_option(mcpwp_api_keys) in code")
if re.search(r"update_option\s*\(\s*['\"]mcpwp_api_key['\"]", code):
    problems.append("legacy update_option(mcpwp_api_key) in code")

# --- scoped key creation ---
if "create_scoped_api_key" not in code:
    problems.append("create_scoped_api_key not used")

# --- key creation must not use bare command substitution without file redirect ---
if re.search(r"^KEY=\$\(create_smoke_key", code, re.M):
    problems.append("KEY=$(create_smoke_key ...) still uses command substitution")
if re.search(r"^READONLY_KEY=\$\(create_smoke_key", code, re.M):
    problems.append("READONLY_KEY=$(create_smoke_key ...) still uses command substitution")
if len(re.findall(r"create_smoke_key[^\n]+\|\|\s*exit 2", raw)) < 2:
    problems.append("create_smoke_key calls must end with || exit 2")
if re.search(r"create_smoke_key[^\n]+\|\|\s*exit 0", raw):
    problems.append("create_smoke_key must not use || exit 0")

# --- die2 stderr + must exit 2 ---
die2_m = re.search(r"die2\(\)\s*\{(.*?)^\}", code, re.S | re.M)
if die2_m:
    body = die2_m.group(1)
    if "exit 2" not in body:
        problems.append("die2 must exit 2")
    if re.search(r"\bexit\s+0\b", body):
        problems.append("die2 must not exit 0")
    if "NO RESULT OBSERVED" in body and ">&2" not in body.split("NO RESULT OBSERVED")[1].split("\n")[0]:
        problems.append("die2 NO RESULT line must go to stderr")
else:
    problems.append("die2() function not found")

if re.search(r'\bKEY\s*=\s*"mcpwp_', code):
    problems.append("hardcoded KEY=mcpwp_* in code")

# --- wpcli / wordpress:cli ---
wpcli_body = ""
m = re.search(r"^wpcli\(\)\s*\{([^}]*)\}", code, re.M | re.S)
if not m:
    problems.append("wpcli() function not found")
else:
    wpcli_body = m.group(1)
    if "SMOKE_WPCLI_MEMORY" not in wpcli_body or "memory_limit" not in wpcli_body:
        problems.append("wpcli() missing SMOKE_WPCLI_MEMORY / memory_limit")
    if "wordpress:cli" not in wpcli_body:
        problems.append("wpcli() must invoke wordpress:cli")

code_outside_wpcli = code
if m:
    code_outside_wpcli = code[: m.start()] + code[m.end() :]
for i, line in enumerate(code_outside_wpcli.splitlines(), 1):
    if "wordpress:cli" in line:
        problems.append(f"wordpress:cli outside wpcli() at code line ~{i}: {line.strip()[:80]}")

# Bare `wordpress:cli wp` without php -d memory_limit
if re.search(r"wordpress:cli\s+wp\s", code) and "memory_limit" not in code:
    problems.append("bare wordpress:cli wp without memory_limit")

# --- PRO PATHS summary (code echo lines, not header comments) ---
if not re.search(r'echo\s+"PRO PATHS: NOT EXERCISED"', code):
    problems.append('missing code echo "PRO PATHS: NOT EXERCISED"')
if not re.search(r"echo\s+\"PRO PATHS: Elementor Pro plugin activated", code):
    problems.append("missing exercised PRO PATHS summary echo")

# PRO_PATHS_EXERCISED=1 only inside Elementor Pro success path
assigns = [ln for ln in code.splitlines() if re.search(r"PRO_PATHS_EXERCISED\s*=\s*1", ln)]
if len(assigns) != 1:
    problems.append(f"expected exactly one PRO_PATHS_EXERCISED=1 assignment, found {len(assigns)}")
elif "elementor-pro" not in assigns[0] and "SMOKE_ELEMENTOR_PRO_DIR" not in code:
    pass
else:
    idx = code.find(assigns[0])
    ctx = code[max(0, idx - 400) : idx + 80]
    if "elementor-pro" not in ctx and "SMOKE_ELEMENTOR_PRO_DIR" not in ctx:
        problems.append("PRO_PATHS_EXERCISED=1 not in Elementor Pro activation block")

# --- read-only denial ---
if "insufficient_scope" not in code:
    problems.append("read-only denial must assert insufficient_scope")
if "structuredContent.error.code" not in code:
    problems.append("read-only denial must read structuredContent.error.code")

# --- site-info / action-log HTTP 200 ---
if "SI_CODE" not in code or not re.search(r'\$SI_CODE.*==\s*"200"', code):
    problems.append("site-info check must require HTTP 200")
if "AL_CODE" not in code:
    problems.append("action-log check must capture HTTP status")

# --- pinned check names ---
EXPECTED = [
    "installed version is",
    "site-info returns JSON",
    "no key -> 401/403",
    "read-only key denied wp_create_page",
    "MCP initialize",
    "tools/list returns tools",
    "wp_create_page -> id",
    "wp_set_elementor success",
    "wp_get_elementor reads back",
    "front end renders the heading",
    "wp_update_page",
    "baseline content readable after upgrade",
    "action log has entries",
    "approvals list 200",
    "wp_onboard answers",
    "debug.log clean of mcpwp PHP errors",
]
found = re.findall(r'check\s+"([^"]+)"', code)
for prefix in EXPECTED:
    if not any(f == prefix or f.startswith(prefix) for f in found):
        problems.append(f'missing check() named "{prefix}*"')
for f in found:
    if f.startswith("harness inject"):
        continue

if 'check "approvals list 200' not in code:
    problems.append("approvals list assertion missing")

# --- invalid SMOKE_ELEMENTOR_PRO_DIR warning ---
if "not a readable directory" not in code:
    problems.append("missing warning when SMOKE_ELEMENTOR_PRO_DIR is set but invalid")

if problems:
    for p in problems:
        print(f"FAIL: {p}")
    sys.exit(1)
print("PASS: static contract")
PY
}

if [[ "${1:-}" == "--self-test" ]]; then
	SOURCE="${RELEASE_SMOKE_SOURCE:-$SMOKE_DEFAULT}"
	WORK="$(mktemp -d "${TMPDIR:-/tmp}/release-smoke-mutations.XXXXXX")" || exit 2
	trap 'rm -rf "$WORK"' EXIT

	python3 - "$SOURCE" "$WORK" <<'PY'
import os
import re
import sys

src = open(sys.argv[1], encoding="utf-8").read()
out = sys.argv[2]
open(os.path.join(out, "baseline.sh"), "w", encoding="utf-8").write(src)

def write(name, text):
    open(os.path.join(out, f"mutant-{name}.sh"), "w", encoding="utf-8").write(text)

mutations = {}

def mut(name, fn):
    mutations[name] = fn(src)

mut("drop-not-exercised-echo", lambda s: s.replace('echo "PRO PATHS: NOT EXERCISED"', 'echo "PRO PATHS: skipped"'))
mut("hardcode-pro-paths-exercised", lambda s: s.replace("PRO_PATHS_EXERCISED=0", "PRO_PATHS_EXERCISED=1", 1))
mut("delete-option-api-keys-spaced", lambda s: s.replace(
    "create_smoke_key", "wpcli eval \"delete_option( 'mcpwp_api_keys' );\" >/dev/null; create_smoke_key", 1))
mut("update-option-legacy-key", lambda s: s.replace(
    "create_smoke_key", "wpcli eval \"update_option( 'mcpwp_api_key', 'x' );\" >/dev/null; create_smoke_key", 1))
mut("bare-wordpress-cli", lambda s: s.replace(
    'wpcli plugin get mcpwp --field=version',
    'docker run --rm wordpress:cli wp plugin get mcpwp --field=version', 1))
mut("memory-flag-comment-only", lambda s: s.replace(
    'php -d "memory_limit=${SMOKE_WPCLI_MEMORY}"',
    'php # memory_limit=${SMOKE_WPCLI_MEMORY}\n\t\t# /usr/local/bin/wp', 1))
mut("wp-rig-db-exec", lambda s: s.replace(
    f'docker exec "$DB" mariadb',
    'docker exec wp-rig-db mariadb', 1))
mut("die2-exit-0", lambda s: re.sub(r"(die2\(\)\s*\{.*?)exit 2", r"\1exit 0", s, count=1, flags=re.S))
mut("drop-approvals-check", lambda s: s.replace('check "approvals list 200 (got $AP)" $?', ""))
mut("key-command-substitution", lambda s: s.replace(
    'create_smoke_key "Release smoke" "read,write,admin" admin >"$WORK/admin.key" || exit 2\nKEY=$(<"$WORK/admin.key")',
    'KEY=$(create_smoke_key "Release smoke" "read,write,admin" admin)'))
mut("drop-create-scoped", lambda s: s.replace("create_scoped_api_key", "create_legacy_api_key"))
mut("drop-insufficient-scope", lambda s: s.replace("insufficient_scope", "any_error"))
mut("exit-2-becomes-0", lambda s: s.replace('|| exit 2', '|| exit 0', 2))
mut("drop-readonly-check", lambda s: re.sub(
    r'\n# 2b\. Read-only.*?\ncheck "read-only key denied wp_create_page".*?\n', '\n', s, count=1, flags=re.S))
mut("site-info-no-http-200", lambda s: s.replace("[[ \"$SI_CODE\" == \"200\" ]]", "[[ \"$SI_CODE\" == \"999\" ]]"))
mut("hardcoded-key-comment", lambda s: s.replace(
    'KEY=$(<"$WORK/admin.key")',
    'KEY="mcpwp_hardcoded_smoke_test_key"\nKEY=$(<"$WORK/admin.key")'))
for name, body in mutations.items():
    write(name, body)
PY

	if run_static_checker "$WORK/baseline.sh" >/dev/null 2>&1; then
		pass "mutation self-test: baseline accepted"
	else
		fail "mutation self-test: baseline rejected"
	fi

	for mutant in "$WORK"/mutant-*.sh; do
		name="$(basename "$mutant" .sh)"
		if run_static_checker "$mutant" >/dev/null 2>&1; then
			fail "mutation self-test: ${name#mutant-} should fail but passed"
		else
			pass "mutation self-test: ${name#mutant-} rejected"
		fi
	done

	echo "verify_release_smoke_test (self-test): $PASS passed, $FAIL failed"
	[[ "$FAIL" -eq 0 ]] || exit 1
	exit 0
fi

SMOKE="${RELEASE_SMOKE_SCRIPT:-$SMOKE_DEFAULT}"
if run_static_checker "$SMOKE" >/dev/null 2>&1; then
	pass "static contract"
else
	run_static_checker "$SMOKE" >&2 || true
	fail "static contract"
fi

run_behaviour() {
	local candidate="${1:-}"
	[[ -n "$candidate" && -f "$candidate" ]] || return 0
	command -v docker >/dev/null 2>&1 || {
		fail "behaviour: docker not available"
		return
	}
	command -v jq >/dev/null 2>&1 || {
		fail "behaviour: jq not available"
		return
	}

	local name="smkbeh$$"
	local port=9198
	local log

	log="$(mktemp)"
	set +e
	SMOKE_INJECT_KEY_FAIL=1 bash "$SMOKE" --candidate "$candidate" --name "$name" --port "$port" >"$log" 2>&1
	local code=$?
	set -e
	docker rm -f -v "${name}-wp" "${name}-db" >/dev/null 2>&1 || true
	docker network rm "${name}-net" >/dev/null 2>&1 || true

	if [[ "$code" -eq 2 ]] && grep -q "COULD NOT RUN" "$log" && ! grep -q "passed, .* failed" "$log"; then
		pass "behaviour: inject key fail -> exit 2"
	else
		fail "behaviour: inject key fail expected exit 2 without check summary (got $code)"
		tail -20 "$log" >&2
	fi
	rm -f "$log"

	name="smkbeh2$$"
	port=9197
	log="$(mktemp)"
	set +e
	SMOKE_INJECT_CHECK_FAIL=1 bash "$SMOKE" --candidate "$candidate" --name "$name" --port "$port" >"$log" 2>&1
	code=$?
	set -e
	docker rm -f -v "${name}-wp" "${name}-db" >/dev/null 2>&1 || true
	docker network rm "${name}-net" >/dev/null 2>&1 || true

	if [[ "$code" -eq 1 ]] && grep -q "harness inject check fail" "$log" && grep -q "PRO PATHS: NOT EXERCISED" "$log"; then
		pass "behaviour: inject check fail -> exit 1 with NOT EXERCISED"
	else
		fail "behaviour: inject check fail expected exit 1 (got $code)"
		tail -25 "$log" >&2
	fi
	rm -f "$log"
}

if [[ "${RELEASE_SMOKE_BEHAVIOUR:-}" == "1" ]]; then
	CAND="${RELEASE_SMOKE_CANDIDATE:-$ROOT_DIR/mcpwp/scripts/mcpwp-selfhosted-3.14.20.zip}"
	if [[ -f "$CAND" ]]; then
		run_behaviour "$CAND"
	else
		echo "skip behaviour: no candidate ZIP at $CAND (set RELEASE_SMOKE_CANDIDATE)" >&2
	fi
fi

echo "verify_release_smoke_test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
