#!/usr/bin/env bash
#
# Build the Agency distribution zip of MCPWP.
#
# All Pro features are permanently enabled via MCPWP_PRO=true injected at
# build time. No Freemius SDK, no license key required, no wp-config.php
# changes needed. Includes the self-update channel (updates.mcpwp.net).
#
# Intended for: agency clients, white-label deployments, and internal use.
#
# Usage:
#   bash scripts/build-agency.sh
#   bash scripts/build-agency.sh --version 3.2.7

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="mcpwp"
DIST_NAME="mcpwp-agency"

VERSION=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="$2"; shift 2 ;;
		*) echo "Unknown option: $1"; exit 1 ;;
	esac
done

if [[ -z "$VERSION" ]]; then
	VERSION=$(grep -m1 "Version:" "$PLUGIN_DIR/$PLUGIN_SLUG.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
fi

if [[ -z "$VERSION" ]]; then
	echo "Error: Could not determine version."
	exit 1
fi

echo "==> Building $DIST_NAME v$VERSION (all Pro features unlocked)"

BUILD_DIR=$(mktemp -d)
DEST="$BUILD_DIR/$PLUGIN_SLUG"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "    Copying plugin files..."
cp -a "$PLUGIN_DIR" "$DEST"

# Remove WP.org banner/icon assets (not needed in plugin zip)
rm -f "$DEST"/assets/banner-*.png
rm -f "$DEST"/assets/icon-*.png
rm -f "$DEST"/assets/screenshot-*.png
rm -f "$DEST"/assets/icon.svg

# No Freemius SDK in agency build — license check bypassed at the constant level
rm -rf "$DEST/freemius"
rm -f  "$DEST/includes/freemius-init.php"

echo "    Injecting MCPWP_PRO=true (permanent Pro unlock)..."
python3 - "$DEST/$PLUGIN_SLUG.php" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
content = path.read_text(encoding="utf-8")
needle = "define( 'MCPWP_VERSION'"
replacement = "define( 'MCPWP_PRO', true );\n" + needle
if "define( 'MCPWP_PRO'" not in content:
    content = content.replace(needle, replacement, 1)
path.write_text(content, encoding="utf-8")
PY

echo "    Applying .distignore (keeping updater)..."
if [[ -f "$DEST/.distignore" ]]; then
	while IFS= read -r pattern; do
		[[ -z "$pattern" || "$pattern" == \#* ]] && continue
		# Keep the updater so sites auto-update via updates.mcpwp.net
		[[ "$pattern" == *"class-mcpwp-updater.php"* ]] && continue
		pattern="${pattern#/}"
		find "$DEST" -path "$DEST/$pattern" -exec rm -rf {} + 2>/dev/null || true
	done < "$DEST/.distignore"
	rm -f "$DEST/.distignore"
fi

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

if grep -q "freemius/" "$ZIP_MANIFEST"; then
	echo "    [FAIL] Freemius SDK found in agency zip"
	exit 1
else
	echo "    [OK] No Freemius SDK"
fi

if grep -q "class-mcpwp-updater.php" "$ZIP_MANIFEST"; then
	echo "    [OK] Updater included (auto-updates via updates.mcpwp.net)"
else
	echo "    [WARN] Updater not found"
fi

if grep -q "MCPWP_PRO" "$ZIP_MANIFEST"; then
	echo "    [OK] MCPWP_PRO constant in main file"
fi

FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | tail -1 | awk '{print $2}')
echo "    [INFO] $FILE_COUNT files in zip"
echo ""
echo "All Pro features permanently enabled. No license key or wp-config.php changes needed."
