#!/usr/bin/env bash
# Fail when a test file exists but PHPUnit never runs it.
#
# tests/phpunit.xml scans tests/ with PHPUnit's default suffix (*Test.php) and
# lists everything else file-by-file. Any file named test-foo.php that nobody
# remembered to add to that list is collected by no rule at all: it sits in the
# repo looking like coverage and executes zero times.
#
# On 2026-07-27 three files were in that state — test-elementor-basic.php,
# test-mcp-free-tools.php and test-rest-menus.php, 32 tests between them. One
# of them covered the Elementor save path, which is where #678 lived.
#
# Same shape as the rest of this week's defects: the artefact existed, nothing
# checked that it was reachable, and "never ran" was indistinguishable from
# "ran and passed". A green suite is only meaningful if it ran what you think
# it ran.
#
# Usage: scripts/verify_test_discovery.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TESTS_DIR="$ROOT/mcpwp/tests"
CONFIG="$TESTS_DIR/phpunit.xml"

if [[ ! -f "$CONFIG" ]]; then
	echo "FAIL: $CONFIG not found" >&2
	exit 1
fi

unreachable=()

while IFS= read -r file; do
	rel="${file#"$TESTS_DIR"/}"
	base="$(basename "$rel")"

	# Collected by the directory rule's default *Test.php suffix.
	if [[ "$base" == *Test.php ]]; then
		continue
	fi

	# Otherwise it must be named explicitly in the config.
	if grep -qF "$rel" "$CONFIG" || grep -qF "$base" "$CONFIG"; then
		continue
	fi

	unreachable+=("$rel")
done < <(grep -rlE 'extends[[:space:]]+(PHPUnit\\Framework\\)?TestCase|extends[[:space:]]+PHPUnit' \
	--include='*.php' "$TESTS_DIR" 2>/dev/null | sort)

if [[ ${#unreachable[@]} -gt 0 ]]; then
	echo "FAIL: ${#unreachable[@]} test file(s) define tests that PHPUnit never runs." >&2
	echo "      They match neither the *Test.php suffix nor any <file> entry in" >&2
	echo "      mcpwp/tests/phpunit.xml, so their tests silently do not execute:" >&2
	echo >&2
	for f in "${unreachable[@]}"; do
		echo "        $f" >&2
	done
	echo >&2
	echo "      Fix: add a <file>./$( basename "${unreachable[0]}" )</file> entry to" >&2
	echo "      mcpwp/tests/phpunit.xml, or rename the file to end in Test.php." >&2
	exit 1
fi

echo "OK: every test file in mcpwp/tests is reachable by phpunit.xml"
exit 0
