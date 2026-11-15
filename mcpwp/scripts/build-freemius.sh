#!/usr/bin/env bash
#
# Build the Freemius/Pro distribution zip of MCPWP.
#
# This package includes the Freemius SDK and Freemius bootstrap, excludes the
# WordPress.org-only updater, and injects MCPWP_FREEMIUS_BUILD.
#
# Usage:
#   bash scripts/build-freemius.sh
#   bash scripts/build-freemius.sh --version 2.8.6

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="mcpwp"
PLUGIN_MAIN="$PLUGIN_SLUG.php"
DIST_NAME="mcpwp-freemius"

VERSION=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="$2"; shift 2 ;;
		*) echo "Unknown option: $1"; exit 1 ;;
	esac
done

if [[ -z "$VERSION" ]]; then
	VERSION=$(grep -m1 "Version:" "$PLUGIN_DIR/$PLUGIN_MAIN" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
fi

if [[ -z "$VERSION" ]]; then
	echo "Error: Could not determine version."
	exit 1
fi

if [[ ! -d "$PLUGIN_DIR/freemius" ]]; then
	echo "Error: Freemius SDK directory not found: $PLUGIN_DIR/freemius"
	exit 1
fi

if [[ ! -f "$PLUGIN_DIR/includes/freemius-init.php" ]]; then
	echo "Error: Freemius bootstrap not found: $PLUGIN_DIR/includes/freemius-init.php"
	exit 1
fi

echo "==> Building $DIST_NAME v$VERSION for Freemius"

BUILD_DIR=$(mktemp -d)
DEST="$BUILD_DIR/$PLUGIN_SLUG"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "    Copying plugin files..."
cp -a "$PLUGIN_DIR" "$DEST"

# Remove WP.org directory assets (uploaded separately to SVN assets/). The
# plugin icon is copied back after .distignore runs, below.
FREEMIUS_ICON="assets/icon-128x128.png"
rm -f "$DEST"/assets/banner-*.png
rm -f "$DEST"/assets/icon-*.png
rm -f "$DEST"/assets/screenshot-*.png
rm -f "$DEST"/assets/icon.svg

echo "    Injecting MCPWP_FREEMIUS_BUILD constant..."
python3 - "$DEST/$PLUGIN_MAIN" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
content = path.read_text(encoding="utf-8")
needle = "define( 'MCPWP_VERSION'"
replacement = "define( 'MCPWP_FREEMIUS_BUILD', true );\n" + needle
if "define( 'MCPWP_FREEMIUS_BUILD'" not in content:
    content = content.replace(needle, replacement, 1)
path.write_text(content, encoding="utf-8")
PY

if [[ -n "${FREEMIUS_WP_ORG_GATEKEEPER:-}" ]]; then
	echo "    Injecting wp_org_gatekeeper into includes/freemius-init.php..."
	python3 - "$DEST/includes/freemius-init.php" <<'PY'
import os
import re
from pathlib import Path
import sys

path = Path(sys.argv[1])
gatekeeper = os.environ["FREEMIUS_WP_ORG_GATEKEEPER"]
escaped = gatekeeper.replace("\\", "\\\\").replace("'", "\\'")
entry_line = f"\t\t\t\t'wp_org_gatekeeper'   => '{escaped}',"

content = path.read_text(encoding="utf-8")
existing_re = re.compile(r"^[ \t]*'wp_org_gatekeeper'[ \t]*=>[ \t]*'(?:\\.|[^'\\])*',[ \t]*$", re.MULTILINE)
if existing_re.search(content):
    content = existing_re.sub(lambda _m: entry_line, content, count=1)
else:
    anchor = "'is_org_compliant'    => true,"
    if anchor not in content:
        sys.stderr.write("Error: 'is_org_compliant' anchor not found in includes/freemius-init.php\n")
        sys.exit(1)
    content = content.replace(anchor, anchor + "\n" + entry_line, 1)

path.write_text(content, encoding="utf-8")
PY
else
	echo "    [INFO] FREEMIUS_WP_ORG_GATEKEEPER not set; skipping wp_org_gatekeeper injection"
fi

echo "    Applying .distignore with Freemius exceptions..."
if [[ -f "$DEST/.distignore" ]]; then
	while IFS= read -r pattern; do
		[[ -z "$pattern" || "$pattern" == \#* ]] && continue
		[[ "$pattern" == "/freemius" ]] && continue
		[[ "$pattern" == "/includes/freemius-init.php" ]] && continue
		pattern="${pattern#/}"
		find "$DEST" -path "$DEST/$pattern" -exec rm -rf {} + 2>/dev/null || true
	done < "$DEST/.distignore"
	rm -f "$DEST/.distignore"
fi

# Put back exactly one asset: the plugin icon. includes/freemius-init.php hands
# the Freemius SDK assets/icon-128x128.png for its opt-in, connect and pricing
# screens, and this is the only build that ships that bootstrap. Stripping it
# shipped a broken image on every premium install. Copying it back after the
# /assets/*.png rule (rather than exempting the rule) keeps any other PNG added
# to assets/ out of this zip. scripts/verify_plugin_assets.sh enforces it.
cp "$PLUGIN_DIR/$FREEMIUS_ICON" "$DEST/$FREEMIUS_ICON"

# Freemius handles Pro updates; do not ship the legacy self-hosted updater.
rm -f "$DEST/includes/class-mcpwp-updater.php"

# #943 S1 review round 2, BLOCK-1c: core prefers a static uninstall.php over
# a plugin's own register_uninstall_hook() callback — shipping this file
# would silently disable Freemius's after_uninstall cleanup (SDK teardown,
# licence deactivation, uninstall reporting) for every paying customer who
# uninstalls. Not in .distignore on purpose: the Free/Agency builds NEED
# this file (they have no Freemius uninstall hook at all), so it must stay
# removed only here, not for every build.
rm -f "$DEST/uninstall.php"

rm -rf "$DEST/scripts"

OUTPUT_DIR="$PLUGIN_DIR/scripts"
OUTPUT_ZIP="$OUTPUT_DIR/$DIST_NAME-$VERSION.zip"

echo "    Creating zip..."
rm -f "$OUTPUT_ZIP"
cd "$BUILD_DIR"
zip -qr "$OUTPUT_ZIP" "$PLUGIN_SLUG/"

echo ""
echo "==> Done: $OUTPUT_ZIP"
echo ""

echo "    Sanity checks:"
ZIP_MANIFEST="$BUILD_DIR/zip-manifest.txt"
unzip -Z1 "$OUTPUT_ZIP" > "$ZIP_MANIFEST"

if grep -q "freemius/start.php" "$ZIP_MANIFEST"; then
	echo "    [OK] Freemius SDK included"
else
	echo "    [FAIL] Freemius SDK missing"
	exit 1
fi

if grep -q "includes/freemius-init.php" "$ZIP_MANIFEST"; then
	echo "    [OK] Freemius bootstrap included"
else
	echo "    [FAIL] Freemius bootstrap missing"
	exit 1
fi

if grep -Eq "'wp_org_gatekeeper'[[:space:]]*=>" "$PLUGIN_DIR/includes/freemius-init.php"; then
	echo "    [FAIL] Source includes/freemius-init.php contains a hardcoded wp_org_gatekeeper secret"
	exit 1
else
	echo "    [OK] Source includes/freemius-init.php has no hardcoded wp_org_gatekeeper"
fi

if command -v php >/dev/null 2>&1; then
	if php -l "$DEST/includes/freemius-init.php" >/dev/null 2>&1; then
		echo "    [OK] Packaged includes/freemius-init.php PHP syntax valid"
	else
		echo "    [FAIL] Packaged includes/freemius-init.php failed PHP syntax check"
		exit 1
	fi
fi

if [[ -n "${FREEMIUS_WP_ORG_GATEKEEPER:-}" ]]; then
	if unzip -p "$OUTPUT_ZIP" mcpwp/includes/freemius-init.php | grep -Eq "'wp_org_gatekeeper'[[:space:]]*=>"; then
		echo "    [OK] wp_org_gatekeeper injected into packaged Freemius zip"
	else
		echo "    [FAIL] FREEMIUS_WP_ORG_GATEKEEPER was set, but wp_org_gatekeeper is missing in packaged zip"
		exit 1
	fi
else
	if unzip -p "$OUTPUT_ZIP" mcpwp/includes/freemius-init.php | grep -Eq "'wp_org_gatekeeper'[[:space:]]*=>"; then
		echo "    [FAIL] wp_org_gatekeeper unexpectedly present when FREEMIUS_WP_ORG_GATEKEEPER is unset"
		exit 1
	else
		echo "    [OK] No wp_org_gatekeeper in zip (FREEMIUS_WP_ORG_GATEKEEPER unset)"
	fi
fi

if grep -qx "mcpwp/$FREEMIUS_ICON" "$ZIP_MANIFEST"; then
	echo "    [OK] Freemius plugin icon included ($FREEMIUS_ICON)"
else
	echo "    [FAIL] Freemius plugin icon missing ($FREEMIUS_ICON) — opt-in screens would show a broken image"
	exit 1
fi

# The only image under assets/ must be the icon: anything else is a WP.org
# directory asset (or a new file) that .distignore meant to keep out.
ASSET_IMAGES="$(grep -iE "^mcpwp/assets/[^/]+\.(png|svg|jpe?g|gif|webp)$" "$ZIP_MANIFEST" || true)"
if [[ "$ASSET_IMAGES" == "mcpwp/$FREEMIUS_ICON" ]]; then
	echo "    [OK] assets/ ships only the plugin icon"
else
	echo "    [FAIL] assets/ images in the Freemius zip must be exactly $FREEMIUS_ICON, got:"
	printf '%s\n' "$ASSET_IMAGES" | sed 's/^/           /'
	exit 1
fi

ASSET_CHECK="$PLUGIN_DIR/../scripts/verify_plugin_assets.sh"
if [[ ! -f "$ASSET_CHECK" ]]; then
	echo "    [FAIL] $ASSET_CHECK not found; cannot verify shipped asset references"
	exit 1
fi
bash "$ASSET_CHECK" "$OUTPUT_ZIP" 2>&1 | sed 's/^/    /'

if grep -q "includes/class-mcpwp-updater.php" "$ZIP_MANIFEST"; then
	echo "    [FAIL] Legacy updater should not be in Freemius build"
	exit 1
else
	echo "    [OK] Legacy updater excluded"
fi

# Issue #863: the paid Freemius build must not be able to self-grant Pro
# without an actual Freemius entitlement.
if grep -q "includes/agency-license.php" "$ZIP_MANIFEST"; then
	echo "    [FAIL] Agency license marker found in Freemius zip!"
	exit 1
else
	echo "    [OK] No agency license marker"
fi

if grep -Eq "defined\(\s*'MCPWP_PRO'\s*\)" "$DEST/includes/class-mcpwp-license.php"; then
	echo "    [FAIL] class-mcpwp-license.php still honours a wp-config.php MCPWP_PRO constant"
	exit 1
else
	echo "    [OK] No wp-config.php MCPWP_PRO override in license class"
fi

# BLOCK-1c: a static uninstall.php in this ZIP would disable Freemius's own
# after_uninstall cleanup for every customer on this build (core uses
# uninstall.php first, never both mechanisms).
if grep -q "^mcpwp/uninstall.php$" "$ZIP_MANIFEST"; then
	echo "    [FAIL] uninstall.php present — this disables Freemius's after_uninstall cleanup"
	exit 1
else
	echo "    [OK] uninstall.php excluded (Freemius owns uninstall on this build)"
fi

FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | tail -1 | awk '{print $2}')
echo "    [INFO] $FILE_COUNT files in zip"
echo ""
echo "Ready to upload to Freemius."
