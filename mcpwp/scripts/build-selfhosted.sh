#!/usr/bin/env bash
#
# Build a self-hosted distribution zip of MCPWP.
#
# Unlike build-wporg.sh, this INCLUDES the auto-updater so sites
# can receive future updates via the mumega.com manifest.
#
# Usage:
#   bash scripts/build-selfhosted.sh
#   bash scripts/build-selfhosted.sh --version 2.6.0

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="mcpwp"
DIST_NAME="mcpwp-selfhosted"

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

echo "==> Building $DIST_NAME v$VERSION (self-hosted, with updater)"

BUILD_DIR=$(mktemp -d)
DEST="$BUILD_DIR/$PLUGIN_SLUG"
trap 'rm -rf "$BUILD_DIR"' EXIT

cp -a "$PLUGIN_DIR" "$DEST"

# Remove WP.org assets (not needed in plugin zip)
rm -f "$DEST"/assets/banner-*.png
rm -f "$DEST"/assets/icon-*.png
rm -f "$DEST"/assets/screenshot-*.png
rm -f "$DEST"/assets/icon.svg

# Remove Freemius SDK (no longer used)
rm -rf "$DEST/freemius"
rm -f  "$DEST/includes/freemius-init.php"

# Apply .distignore EXCEPT the updater line
if [[ -f "$DEST/.distignore" ]]; then
    while IFS= read -r pattern; do
        [[ -z "$pattern" || "$pattern" == \#* ]] && continue
        # SKIP the updater exclusion — we want it in self-hosted builds
        [[ "$pattern" == *"class-mcpwp-updater.php"* ]] && continue
        pattern="${pattern#/}"
        find "$DEST" -path "$DEST/$pattern" -exec rm -rf {} + 2>/dev/null || true
    done < "$DEST/.distignore"
    rm -f "$DEST/.distignore"
fi

rm -rf "$DEST/scripts"

OUTPUT_DIR="$PLUGIN_DIR/scripts"
OUTPUT_ZIP="$OUTPUT_DIR/$DIST_NAME-$VERSION.zip"

rm -f "$OUTPUT_ZIP"
cd "$BUILD_DIR"
zip -qr "$OUTPUT_ZIP" "$PLUGIN_SLUG/"

echo ""
echo "==> Done: $OUTPUT_ZIP"
echo ""

# Sanity
ZIP_MANIFEST="$BUILD_DIR/zip-manifest.txt"
unzip -Z1 "$OUTPUT_ZIP" > "$ZIP_MANIFEST"

if grep -q "freemius/" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Freemius found!"
    exit 1
else
    echo "    [OK] No Freemius"
fi

if grep -q "class-mcpwp-updater.php" "$ZIP_MANIFEST"; then
    echo "    [OK] Updater INCLUDED (self-hosted build)"
else
    echo "    [WARN] Updater NOT found — this defeats the purpose!"
fi

# Issue #863: the public self-hosted build must not be able to self-grant
# Pro via a wp-config.php constant or a leaked agency marker.
if grep -q "includes/agency-license.php" "$ZIP_MANIFEST"; then
    echo "    [FAIL] Agency license marker found in self-hosted zip!"
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

# #943 S1 review round 3, N4: this build has no Freemius SDK at all (removed
# above), so uninstall.php is the ONLY mechanism that will ever drop the
# graph tables on uninstall here. Missing it silently leaves those four
# tables behind forever on every self-hosted uninstall.
if grep -q "^$PLUGIN_SLUG/uninstall.php$" "$ZIP_MANIFEST"; then
    echo "    [OK] uninstall.php present (this build has no Freemius to own uninstall instead)"
else
    echo "    [FAIL] uninstall.php missing — nothing will drop the graph tables on uninstall"
    exit 1
fi

FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | tail -1 | awk '{print $2}')
echo "    [INFO] $FILE_COUNT files in zip"
