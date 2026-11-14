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
(cd "$src" && zip -qr "$WORK/full.zip" mcpwp)

expect pass "all referenced assets shipped" bash "$VERIFY" "$WORK/full.zip"

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

echo "verify_plugin_assets_test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
