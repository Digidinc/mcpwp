#!/usr/bin/env bash
# Tests for scripts/verify_plugin_assets.sh: every reference form it claims to
# read is caught when the file is missing, and it fails closed when it cannot
# check (unsupported grep, PHP listed but not extracted, multi-root zip).
#
# Usage: bash scripts/tests/verify_plugin_assets_test.sh [--self-test]
#
# --self-test: build fixtures, apply known verifier weakenings, and assert each
# would flip a fail-closed case to a pass (so the main harness would fail).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERIFY="$ROOT_DIR/scripts/verify_plugin_assets.sh"
REAL_UNZIP="$(command -v unzip)"

PASS=0
FAIL=0

build_tool_only_path() {
	local dest="$1"
	mkdir -p "$dest"
	local -a tools=(bash unzip grep awk sed mktemp find head cut sort wc tr dirname basename mkdir rm)
	local t real
	for t in "${tools[@]}"; do
		real="$(command -v "$t" || true)"
		if [[ -z "$real" ]]; then
			echo "fixture setup: missing required tool $t" >&2
			return 2
		fi
		ln -sf "$real" "$dest/$t"
	done
	return 0
}

# Build zips and paths shared by the main run and --self-test.
build_test_fixtures() {
	local work="$1"
	src="$work/src"
	mkdir -p "$src/mcpwp/includes/img" "$src/mcpwp/assets"
	cat > "$src/mcpwp/includes/a.php" <<'PHP'
<?php
$a = MCPWP_PLUGIN_DIR . 'assets/icon-128x128.png';
$b = MCPWP_PLUGIN_URL . "assets/Logo.PNG";
$c = plugins_url( 'img/x.svg', __FILE__ );
$d = plugins_url( 'assets/y.webp', MCPWP_PLUGIN_FILE );
$e = plugins_url( "mcpwp/assets/z.gif" );
PHP
	for f in assets/icon-128x128.png assets/Logo.PNG includes/img/x.svg assets/y.webp assets/z.gif; do
		: > "$src/mcpwp/$f"
	done
	cp "$ROOT_DIR/mcpwp/assets/mcpwp-mark-ochre.svg" "$src/mcpwp/assets/"
	cp "$ROOT_DIR/mcpwp/assets/mcpwp-mark-ochre-256.png" "$src/mcpwp/assets/"
	(cd "$src" && zip -qr "$work/full.zip" mcpwp)

	patch_mark_in_zip() {
		local zip_out="$1" mark_rel="$2" payload="$3"
		local stage="$work/patch-$$"
		rm -rf "$stage"
		mkdir -p "$stage/mcpwp/$(dirname "$mark_rel")"
		cp "$work/full.zip" "$stage/pkg.zip"
		printf '%s' "$payload" > "$stage/mcpwp/$mark_rel"
		(
			cd "$stage"
			zip -q pkg.zip "mcpwp/$mark_rel"
		)
		mv "$stage/pkg.zip" "$zip_out"
		rm -rf "$stage"
	}
	patch_mark_in_zip "$work/mark-bad-svg.zip" "assets/mcpwp-mark-ochre.svg" "not-the-approved-svg"
	patch_mark_in_zip "$work/mark-bad-png.zip" "assets/mcpwp-mark-ochre-256.png" "not-the-approved-png"

	bad_zip="$work/bad-hash.zip"
	bad_tree="$work/bad-tree"
	mkdir -p "$bad_tree"
	unzip -q "$work/full.zip" -d "$bad_tree"
	printf 'not-the-approved-png' > "$bad_tree/mcpwp/assets/mcpwp-mark-ochre-256.png"
	( cd "$bad_tree" && zip -qr "$bad_zip" mcpwp )

	shim_hash="$work/shim_hash"
	mkdir -p "$shim_hash"
	cat > "$shim_hash/sha256sum" <<'SH'
#!/usr/bin/env bash
exit 127
SH
	chmod +x "$shim_hash/sha256sum"

	TOOL_ONLY_PATH="$work/tool_only_path"
	build_tool_only_path "$TOOL_ONLY_PATH"

	PATH_saved="$PATH"
}

# Apply a weakening to a temp copy; return 0 if the weakened script passes a case
# the main suite expects to fail (so the harness would catch the regression).
weakened_verifier_passes() {
	local label="$1"
	local work="$2"
	local mutated="$work/verify_mutated.sh"
	local got=0
	cp "$VERIFY" "$mutated"
	case "$label" in
		fail-open-no-hash-tool)
			sed -i 's/got_sha="$(unzip -p "$zip" "$mark_path" | sha256_digest)"/got_sha="$want_sha" # mutated: skip digest/' "$mutated"
			got=0
			env PATH="$TOOL_ONLY_PATH" bash "$mutated" "$work/full.zip" >"$work/mut-out" 2>&1 || got=$?
			[[ "$got" -eq 0 ]]
			;;
		skip-mark-digest)
			sed -i 's/\[\[ "$got_sha" != "$want_sha" \]\]/false/' "$mutated"
			got=0
			bash "$mutated" "$bad_zip" >"$work/mut-out" 2>&1 || got=$?
			[[ "$got" -ne 0 ]] && return 1
			got=0
			env PATH="$shim_hash:$PATH_saved" bash "$mutated" "$bad_zip" >"$work/mut-out" 2>&1 || got=$?
			[[ "$got" -eq 0 ]]
			;;
		*)
			echo "unknown mutation label: $label" >&2
			return 2
			;;
	esac
}

assert_mutations_caught_by_harness() {
	local work="$1"
	local label got out
	for label in fail-open-no-hash-tool skip-mark-digest; do
		if weakened_verifier_passes "$label" "$work"; then
			PASS=$((PASS + 1))
		else
			FAIL=$((FAIL + 1))
			echo "not ok: mutation $label should bypass fail-closed checks (harness would not catch it)" >&2
			if [[ -f "$work/mut-out" ]]; then
				sed 's/^/    /' "$work/mut-out" >&2
			fi
		fi
	done
}

run_mutation_self_test() {
	WORK="$(mktemp -d)"
	trap 'rm -rf "$WORK"' EXIT
	build_test_fixtures "$WORK" || exit 2
	assert_mutations_caught_by_harness "$WORK"
	echo "verify_plugin_assets_test (self-test): $PASS passed, $FAIL failed"
	[[ "$FAIL" -eq 0 ]]
}

if [[ "${1:-}" == "--self-test" ]]; then
	run_mutation_self_test
	exit $?
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
build_test_fixtures "$WORK"

expect() {
	local want="$1" label="$2"
	shift 2
	local got=0
	"$@" > "$WORK/out" 2>&1 || got=$?
	if { [[ "$want" == "pass" && "$got" -eq 0 ]] || [[ "$want" == "fail" && "$got" -ne 0 ]]; }; then
		PASS=$((PASS + 1))
	else
		FAIL=$((FAIL + 1))
		echo "not ok: $label (wanted $want, exit $got)" >&2
		sed 's/^/    /' "$WORK/out" >&2
	fi
}

expect pass "all referenced assets shipped" bash "$VERIFY" "$WORK/full.zip"

cp "$WORK/full.zip" "$WORK/mark-missing-svg.zip"
zip -qd "$WORK/mark-missing-svg.zip" "mcpwp/assets/mcpwp-mark-ochre.svg"
expect fail "required brand mark svg missing" bash "$VERIFY" "$WORK/mark-missing-svg.zip"

cp "$WORK/full.zip" "$WORK/mark-missing-png.zip"
zip -qd "$WORK/mark-missing-png.zip" "mcpwp/assets/mcpwp-mark-ochre-256.png"
expect fail "required brand mark png missing" bash "$VERIFY" "$WORK/mark-missing-png.zip"

patch_mark_in_zip() {
	local zip_out="$1" mark_rel="$2" payload="$3"
	local stage="$WORK/patch-$$"
	rm -rf "$stage"
	mkdir -p "$stage/mcpwp/$(dirname "$mark_rel")"
	cp "$WORK/full.zip" "$stage/pkg.zip"
	printf '%s' "$payload" > "$stage/mcpwp/$mark_rel"
	(
		cd "$stage"
		zip -q pkg.zip "mcpwp/$mark_rel"
	)
	mv "$stage/pkg.zip" "$zip_out"
	rm -rf "$stage"
}
expect fail "required brand mark svg wrong sha256" bash "$VERIFY" "$WORK/mark-bad-svg.zip"
expect fail "required brand mark png wrong sha256" bash "$VERIFY" "$WORK/mark-bad-png.zip"

for f in assets/icon-128x128.png assets/Logo.PNG includes/img/x.svg assets/y.webp assets/z.gif; do
	cp "$WORK/full.zip" "$WORK/missing.zip"
	zip -qd "$WORK/missing.zip" "mcpwp/$f"
	expect fail "missing $f is caught" bash "$VERIFY" "$WORK/missing.zip"
done

mkdir -p "$WORK/src/other"
echo '<?php' > "$WORK/src/other/b.php"
(cd "$WORK/src" && zip -qr "$WORK/multi.zip" other mcpwp)
expect fail "multi-root zip" bash "$VERIFY" "$WORK/multi.zip"

shim="$WORK/shim"
mkdir -p "$shim"
cat > "$shim/unzip" <<SH
#!/usr/bin/env bash
if [[ "\$1" == "-Z1" ]]; then exec "$REAL_UNZIP" "\$@"; fi
exit 0
SH
chmod +x "$shim/unzip"
expect fail "PHP listed but not extracted" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"

cat > "$shim/unzip" <<SH
#!/usr/bin/env bash
if [[ "\$1" == "-Z1" ]]; then exec "$REAL_UNZIP" "\$@"; fi
exit 9
SH
expect fail "PHP extraction error" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"
rm -f "$shim/unzip"

printf '#!/usr/bin/env bash\nexit 2\n' > "$shim/grep"
chmod +x "$shim/grep"
expect fail "unsupported grep (verify_plugin_assets)" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"
expect fail "unsupported grep -P (verify_distignore_refs)" env PATH="$shim:$PATH" bash "$ROOT_DIR/scripts/verify_distignore_refs.sh"
rm -f "$shim/grep"

expect fail "no arguments" bash "$VERIFY"
expect fail "nonexistent zip" bash "$VERIFY" "$WORK/nope.zip"

# macOS often ships shasum without sha256sum; the verifier must still digest marks.
expect pass "shasum -a 256 fallback for brand mark digest" env PATH="$shim_hash:$PATH" bash "$VERIFY" "$WORK/full.zip"

expect fail "wrong brand mark sha256" bash "$VERIFY" "$bad_zip"

# sha256sum absent: wrong mark digest via shasum fallback must still fail.
expect fail "wrong brand mark via shasum fallback" env PATH="$shim_hash:$PATH" bash "$VERIFY" "$bad_zip"

# Neither sha256sum nor shasum on PATH: digest step must hit the no-tool branch.
got=0
env PATH="$TOOL_ONLY_PATH" bash "$VERIFY" "$WORK/full.zip" >"$WORK/out" 2>&1 || got=$?
if [[ "$got" -ne 0 ]] && grep -q 'sha256sum or shasum -a 256 is required' "$WORK/out"; then
	PASS=$((PASS + 1))
else
	FAIL=$((FAIL + 1))
	echo "not ok: no sha256sum or shasum available (wanted fail + no-tool message, exit $got)" >&2
	sed 's/^/    /' "$WORK/out" >&2
fi

assert_mutations_caught_by_harness "$WORK"

echo "verify_plugin_assets_test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]