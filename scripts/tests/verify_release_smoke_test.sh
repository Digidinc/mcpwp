#!/usr/bin/env bash
# Static regression tests for scripts/release_smoke.sh (issue #1241).
#
# Usage: bash scripts/tests/verify_release_smoke_test.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SMOKE="$ROOT_DIR/scripts/release_smoke.sh"

PASS=0
FAIL=0

fail() {
	FAIL=$((FAIL + 1))
	echo "not ok: $*" >&2
}

pass() {
	PASS=$((PASS + 1))
}

[[ -f "$SMOKE" ]] || { echo "missing $SMOKE" >&2; exit 1; }

if grep -qE 'wp-rig-wp-dev|docker cp.*wp-rig' "$SMOKE"; then
	fail "script still references wp-rig for docker cp"
else
	pass "no wp-rig docker cp"
fi

if grep -q 'SMOKE_WPCLI_MEMORY' "$SMOKE" && grep -q 'php -d "memory_limit=' "$SMOKE"; then
	pass "SMOKE_WPCLI_MEMORY applied in wpcli"
else
	fail "SMOKE_WPCLI_MEMORY missing or not applied via php -d in wpcli"
fi

if grep -qE 'wordpress:cli[[:space:]]+wp[[:space:]]' "$SMOKE"; then
	fail "bare wordpress:cli wp invocation found (must use wpcli())"
else
	pass "no bare wordpress:cli wp"
fi

if grep -q "update_option('mcpwp_api_key'" "$SMOKE" || grep -q 'update_option("mcpwp_api_key"' "$SMOKE"; then
	fail "legacy update_option(mcpwp_api_key) seeding still present"
else
	pass "no legacy mcpwp_api_key seeding"
fi

if grep -q "delete_option('mcpwp_api_keys'" "$SMOKE" || grep -q 'delete_option("mcpwp_api_keys"' "$SMOKE"; then
	fail "delete_option(mcpwp_api_keys) present"
else
	pass "key store not deleted"
fi

if grep -q 'create_scoped_api_key' "$SMOKE"; then
	pass "uses create_scoped_api_key"
else
	fail "create_scoped_api_key not used for smoke key"
fi

if grep -q 'PRO PATHS: NOT EXERCISED' "$SMOKE" && grep -q 'PRO PATHS: exercised' "$SMOKE"; then
	pass "PRO PATHS summary lines present"
else
	fail "PRO PATHS summary lines missing"
fi

if grep -q 'SMOKE_ELEMENTOR_PRO_DIR' "$SMOKE"; then
	pass "SMOKE_ELEMENTOR_PRO_DIR supported"
else
	fail "SMOKE_ELEMENTOR_PRO_DIR missing"
fi

grep -qE '^#   0  every check ran and passed' "$SMOKE" && grep -qE '^#   1  the site came up' "$SMOKE" && grep -qE '^#   2  could not run' "$SMOKE" \
	&& pass "exit code contract documented" || fail "exit code contract missing"

echo "verify_release_smoke_test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]