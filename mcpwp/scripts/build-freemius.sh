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

# Remove WP.org directory assets (uploaded separately to SVN assets/).
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

# Freemius handles Pro updates; do not ship the legacy self-hosted updater.
rm -f "$DEST/includes/class-mcpwp-updater.php"
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

FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | tail -1 | awk '{print $2}')
echo "    [INFO] $FILE_COUNT files in zip"
echo ""
echo "Ready to upload to Freemius."
