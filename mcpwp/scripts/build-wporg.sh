#!/usr/bin/env bash
#
# Build a WordPress.org-compliant zip of MCPWP for WordPress.
#
# Builds the free WP.org package.
# Freemius, the legacy updater, and Pro modules are excluded.
#
# Usage:
#   bash scripts/build-wporg.sh
#   bash scripts/build-wporg.sh --version 1.7.0

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SOURCE_SLUG="mcpwp"
WPORG_SLUG="mcpwp"
PLUGIN_MAIN="$SOURCE_SLUG.php"

# Parse version from plugin header if not supplied
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

echo "==> Building $WPORG_SLUG v$VERSION for WordPress.org"

# Create temp build directory
BUILD_DIR=$(mktemp -d)
DEST="$BUILD_DIR/$WPORG_SLUG"
trap 'rm -rf "$BUILD_DIR"' EXIT

# Copy plugin tree
echo "    Copying plugin files..."
cp -a "$PLUGIN_DIR" "$DEST"

# ── Strip WP.org directory assets (uploaded separately to SVN assets/) ──
rm -f "$DEST"/assets/banner-*.png
# Icon files stay in the zip (admin header + Freemius). This build drops
# includes/freemius-init.php (the only shipped code that names the 256px PNG).
# class-mcpwp-admin-brand.php still names assets/mcpwp-mark-ochre.svg in every
# build. Only build-freemius.sh keeps freemius-init.php. scripts/verify_plugin_assets.sh
# fails the build if a shipped PHP reference is missing its file.
rm -f "$DEST"/assets/icon-*.png
rm -f "$DEST"/assets/screenshot-*.png
rm -f "$DEST"/assets/icon.svg

# ── Remove Freemius SDK (no longer used) ──
rm -rf "$DEST/freemius"
rm -f  "$DEST/includes/freemius-init.php"

# ── Remove Pro modules from the free WP.org package ─────────────
rm -rf "$DEST/includes/pro"

# ── Inject MCPWP_WPORG_BUILD constant for WP.org compliance ─────
echo "    Injecting MCPWP_WPORG_BUILD constant..."
python3 - "$DEST/$PLUGIN_MAIN" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
content = path.read_text(encoding="utf-8")
needle = "define( 'MCPWP_VERSION'"
replacement = "define( 'MCPWP_WPORG_BUILD', true );\n" + needle
if "define( 'MCPWP_WPORG_BUILD'" not in content:
    content = content.replace(needle, replacement, 1)
path.write_text(content, encoding="utf-8")
PY

# ── Apply .distignore exclusions ────────────────────────────────
echo "    Applying .distignore..."
if [[ -f "$DEST/.distignore" ]]; then
    while IFS= read -r pattern; do
        # Skip empty lines and comments
        [[ -z "$pattern" || "$pattern" == \#* ]] && continue
        # Remove leading slash for find compatibility
        pattern="${pattern#/}"
        # Use find to match and remove
        find "$DEST" -path "$DEST/$pattern" -exec rm -rf {} + 2>/dev/null || true
    done < "$DEST/.distignore"
    rm -f "$DEST/.distignore"
fi

# Admin header badge assets (referenced via MCPWP_PLUGIN_URL in admin partials).
for _mark in assets/mcpwp-mark-ochre.svg assets/mcpwp-mark-ochre-256.png; do
	cp "$PLUGIN_DIR/$_mark" "$DEST/$_mark"
done

# Remove build scripts from output (scripts/ is already in .distignore)
rm -rf "$DEST/scripts"

# ── Build zip ───────────────────────────────────────────────────
OUTPUT_DIR="$PLUGIN_DIR/scripts"
OUTPUT_ZIP="$OUTPUT_DIR/$WPORG_SLUG-$VERSION.zip"

echo "    Creating zip..."
rm -f "$OUTPUT_ZIP"
cd "$BUILD_DIR"
zip -qr "$OUTPUT_ZIP" "$WPORG_SLUG/"

echo ""
echo "==> Done: $OUTPUT_ZIP"
echo ""

# Sanity checks
echo "    Sanity checks:"
ZIP_MANIFEST="$BUILD_DIR/zip-manifest.txt"
unzip -Z1 "$OUTPUT_ZIP" > "$ZIP_MANIFEST"

if grep -q "freemius/" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Freemius SDK found in zip!"
    exit 1
else
    echo "    [OK] No Freemius SDK"
fi

if grep -q "includes/pro/" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Pro modules found in WP.org zip!"
    exit 1
else
    echo "    [OK] No Pro modules"
fi

if grep -q "includes/class-mcpwp-updater.php" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Legacy updater found in WP.org zip!"
    exit 1
else
    echo "    [OK] No legacy updater"
fi

# Issue #863: the WP.org build must never be able to self-grant Pro.
if grep -q "includes/agency-license.php" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Agency license marker found in WP.org zip!"
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

ASSET_CHECK="$PLUGIN_DIR/../scripts/verify_plugin_assets.sh"
if [[ ! -f "$ASSET_CHECK" ]]; then
    echo "    [FAIL] $ASSET_CHECK not found; cannot verify shipped asset references"
    exit 1
fi
bash "$ASSET_CHECK" "$OUTPUT_ZIP" 2>&1 | sed 's/^/    /'

FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | tail -1 | awk '{print $2}')
echo "    [INFO] $FILE_COUNT files in zip"
echo ""
echo "Ready to submit to WordPress.org!"
