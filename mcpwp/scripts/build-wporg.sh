#!/usr/bin/env bash
# Build the authoritative WordPress.org artifact.
#
# Usage:
#   bash mcpwp/scripts/build-wporg.sh
#   bash mcpwp/scripts/build-wporg.sh --version 3.4.13

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SOURCE_MAIN="mcpwp.php"
WPORG_SLUG="mumega-mcp"
WPORG_MAIN="mumega-mcp.php"

VERSION=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version)
			[[ $# -ge 2 ]] || { echo "Error: --version requires a value." >&2; exit 1; }
			VERSION="$2"
			shift 2
			;;
		*)
			echo "Error: unknown option: $1" >&2
			exit 1
			;;
	esac
done

SOURCE_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_DIR/$SOURCE_MAIN" | head -n 1 | tr -d '[:space:]')"
if [[ -z "$SOURCE_VERSION" ]]; then
	echo "Error: could not determine the plugin version." >&2
	exit 1
fi

if [[ -n "$VERSION" && "$VERSION" != "$SOURCE_VERSION" ]]; then
	echo "Error: --version $VERSION does not match source header version $SOURCE_VERSION." >&2
	exit 1
fi
VERSION="$SOURCE_VERSION"

BUILD_DIR="$(mktemp -d)"
DEST="$BUILD_DIR/$WPORG_SLUG"
OUTPUT_ZIP="$SCRIPT_DIR/$WPORG_SLUG-$VERSION.zip"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "==> Building $WPORG_SLUG v$VERSION for WordPress.org"
cp -a "$PLUGIN_DIR" "$DEST"

# WordPress.org directory assets are uploaded separately to SVN assets/.
rm -f "$DEST"/assets/banner-*.png
rm -f "$DEST"/assets/icon-*.png
rm -f "$DEST"/assets/screenshot-*.png
rm -f "$DEST"/assets/*.svg
rm -f "$DEST/assets/README.md"

# Apply source distribution exclusions before removing the exclusion file itself.
if [[ -f "$DEST/.distignore" ]]; then
	while IFS= read -r pattern; do
		[[ -z "$pattern" || "$pattern" == \#* ]] && continue
		pattern="${pattern#/}"
		find "$DEST" -path "$DEST/$pattern" -exec rm -rf {} + 2>/dev/null || true
	done < "$DEST/.distignore"
	rm -f "$DEST/.distignore"
fi

rm -rf "$DEST/scripts"
mv "$DEST/$SOURCE_MAIN" "$DEST/$WPORG_MAIN"

# Normalize file timestamps and feed zip a sorted file list for reproducibility.
find "$DEST" -type f -exec touch -t 198001010000 {} +
rm -f "$OUTPUT_ZIP"
(
	cd "$BUILD_DIR"
	find "$WPORG_SLUG" -type f -print | LC_ALL=C sort | zip -q -X "$OUTPUT_ZIP" -@
)

bash "$SCRIPT_DIR/verify-wporg-artifact.sh" "$OUTPUT_ZIP"
echo "==> Done: $OUTPUT_ZIP"
