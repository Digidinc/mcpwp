#!/usr/bin/env bash
# Tests for scripts/verify_plugin_assets.sh: every reference form it claims to
# read is caught when the file is missing, and it fails closed when it cannot
# check (unsupported grep, PHP listed but not extracted, multi-root zip).
#
# Usage: bash scripts/tests/verify_plugin_assets_test.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERIFY="$ROOT_DIR/scripts/verify_plugin_assets.sh"
REAL_UNZIP="$(command -v unzip)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0

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

# A plugin zip using every supported reference form, with every asset shipped.
src="$WORK/src"
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
(cd "$src" && zip -qr "$WORK/full.zip" mcpwp)

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
patch_mark_in_zip "$WORK/mark-bad-svg.zip" "assets/mcpwp-mark-ochre.svg" "not-the-approved-svg"
expect fail "required brand mark svg wrong sha256" bash "$VERIFY" "$WORK/mark-bad-svg.zip"
patch_mark_in_zip "$WORK/mark-bad-png.zip" "assets/mcpwp-mark-ochre-256.png" "not-the-approved-png"
expect fail "required brand mark png wrong sha256" bash "$VERIFY" "$WORK/mark-bad-png.zip"

for f in assets/icon-128x128.png assets/Logo.PNG includes/img/x.svg assets/y.webp assets/z.gif; do
	cp "$WORK/full.zip" "$WORK/missing.zip"
	zip -qd "$WORK/missing.zip" "mcpwp/$f"
	expect fail "missing $f is caught" bash "$VERIFY" "$WORK/missing.zip"
done

# A zip with two top-level directories cannot be checked against one root.
mkdir -p "$src/other"
echo '<?php' > "$src/other/b.php"
(cd "$src" && zip -qr "$WORK/multi.zip" other mcpwp)
expect fail "multi-root zip" bash "$VERIFY" "$WORK/multi.zip"

# unzip lists the PHP files but extracts none (exit 0): nothing was checked.
shim="$WORK/shim"
mkdir -p "$shim"
cat > "$shim/unzip" <<SH
#!/usr/bin/env bash
if [[ "\$1" == "-Z1" ]]; then exec "$REAL_UNZIP" "\$@"; fi
exit 0
SH
chmod +x "$shim/unzip"
expect fail "PHP listed but not extracted" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"

# unzip extraction errors.
cat > "$shim/unzip" <<SH
#!/usr/bin/env bash
if [[ "\$1" == "-Z1" ]]; then exec "$REAL_UNZIP" "\$@"; fi
exit 9
SH
expect fail "PHP extraction error" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"
rm -f "$shim/unzip"

# A grep that cannot run the patterns must fail, not match nothing and pass.
printf '#!/usr/bin/env bash\nexit 2\n' > "$shim/grep"
chmod +x "$shim/grep"
expect fail "unsupported grep (verify_plugin_assets)" env PATH="$shim:$PATH" bash "$VERIFY" "$WORK/full.zip"
expect fail "unsupported grep -P (verify_distignore_refs)" env PATH="$shim:$PATH" bash "$ROOT_DIR/scripts/verify_distignore_refs.sh"
rm -f "$shim/grep"

expect fail "no arguments" bash "$VERIFY"
expect fail "nonexistent zip" bash "$VERIFY" "$WORK/nope.zip"

# macOS often ships shasum without sha256sum; the verifier must still digest marks.
shim_hash="$WORK/shim_hash"
mkdir -p "$shim_hash"
cat > "$shim_hash/sha256sum" <<'SH'
#!/usr/bin/env bash
exit 127
SH
chmod +x "$shim_hash/sha256sum"
expect pass "shasum -a 256 fallback for brand mark digest" env PATH="$shim_hash:$PATH" bash "$VERIFY" "$WORK/full.zip"

# Wrong digest for a shipped mark must fail (not silently pass).
bad_zip="$WORK/bad-hash.zip"
bad_tree="$WORK/bad-tree"
mkdir -p "$bad_tree"
unzip -q "$WORK/full.zip" -d "$bad_tree"
printf 'not-the-approved-png' > "$bad_tree/mcpwp/assets/mcpwp-mark-ochre-256.png"
( cd "$bad_tree" && zip -qr "$bad_zip" mcpwp )
expect fail "wrong brand mark sha256" bash "$VERIFY" "$bad_zip"

# sha256sum absent: wrong mark digest via shasum fallback must still fail.
expect fail "wrong brand mark via shasum fallback" env PATH="$shim_hash:$PATH" bash "$VERIFY" "$bad_zip"

# Neither sha256sum nor shasum: digest step must fail closed (keep bash/unzip/grep on PATH).
no_hash_shim="$WORK/no_hash_shim"
mkdir -p "$no_hash_shim"
printf '#!/usr/bin/env bash\nexit 127\n' > "$no_hash_shim/sha256sum"
printf '#!/usr/bin/env bash\nexit 127\n' > "$no_hash_shim/shasum"
chmod +x "$no_hash_shim/sha256sum" "$no_hash_shim/shasum"
PATH_saved="$PATH"
expect fail "no sha256sum or shasum available" env PATH="$no_hash_shim:$PATH_saved" bash "$VERIFY" "$WORK/full.zip"

# Mutation probes — the suite must fail when the verifier is weakened.
run_mutated_suite() {
	local label="$1"
	local mutated="$WORK/verify_mutated.sh"
	cp "$VERIFY" "$mutated"
	case "$label" in
		"fail-open when no hash tool")
			sed -i 's/got_sha="$(unzip -p "$zip" "$mark_path" | sha256_digest)"/got_sha="$want_sha" # mutated: skip digest/' "$mutated"
			;;
		"skip mark digest comparison")
			sed -i 's/\[\[ "$got_sha" != "$want_sha" \]\]/false/' "$mutated"
			;;
		*)
			echo "not ok: unknown mutation $label" >&2
			FAIL=$((FAIL + 1))
			return
			;;
	esac
	local mpass=0 mfail=0 got=0
	case "$label" in
		"fail-open when no hash tool")
			if env PATH="$no_hash_shim:$PATH_saved" bash "$mutated" "$WORK/full.zip" > "$WORK/mut-out" 2>&1; then
				got=0
			else
				got=$?
			fi
			if [[ "$got" -eq 0 ]]; then
				mpass=1
			else
				mfail=1
				echo "not ok: mutation probe $label (weakened verifier still failed; exit $got)" >&2
				sed 's/^/    /' "$WORK/mut-out" >&2
			fi
			;;
		"skip mark digest comparison")
			if bash "$mutated" "$bad_zip" > "$WORK/mut-out" 2>&1; then
				got=0
			else
				got=$?
			fi
			if [[ "$got" -eq 0 ]]; then
				mpass=1
			else
				mfail=1
				echo "not ok: mutation probe $label (weakened verifier still failed; exit $got)" >&2
				sed 's/^/    /' "$WORK/mut-out" >&2
			fi
			if env PATH="$shim_hash:$PATH" bash "$mutated" "$bad_zip" > "$WORK/mut-out" 2>&1; then
				got=0
			else
				got=$?
			fi
			if [[ "$got" -eq 0 ]]; then
				mpass=$((mpass + 1))
			else
				mfail=$((mfail + 1))
				echo "not ok: mutation probe $label shasum path (weakened verifier still failed; exit $got)" >&2
				sed 's/^/    /' "$WORK/mut-out" >&2
			fi
			;;
	esac
	PASS=$((PASS + mpass))
	FAIL=$((FAIL + mfail))
}

run_mutated_suite "fail-open when no hash tool"
run_mutated_suite "skip mark digest comparison"

echo "verify_plugin_assets_test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
