#!/usr/bin/env bash
# Fail if a built plugin zip ships PHP that points at an image file the zip
# does not contain.
#
# Every build script strips the WordPress.org directory assets (banners,
# screenshots, icons) from assets/, and .distignore excludes /assets/*.png and
# /assets/*.svg. includes/freemius-init.php hands the Freemius SDK
# MCPWP_PLUGIN_DIR . 'assets/icon-128x128.png' as the plugin icon, so the
# premium package shipped a broken image on every opt-in, connect and pricing
# screen: the bootstrap was in the zip, its icon was not.
#
# This check reads the zip, not the tree. It scans every shipped PHP file for a
# literal MCPWP_PLUGIN_DIR . '<path>.<png|svg|jpg|jpeg|gif|webp>' reference and
# requires <path> to be in the same zip. Builds that drop freemius-init.php
# (free, self-hosted, agency) pass because nothing they ship names the icon.
#
# Usage:
#   bash scripts/verify_plugin_assets.sh <zip> [<zip> ...]

set -euo pipefail

if [[ $# -eq 0 ]]; then
	echo "Usage: $0 <zip> [<zip> ...]" >&2
	exit 2
fi

command -v unzip >/dev/null 2>&1 || { echo "unzip is required" >&2; exit 1; }

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

FAILED=0

for zip in "$@"; do
	if [[ ! -f "$zip" ]]; then
		echo "FAIL: $zip does not exist" >&2
		FAILED=1
		continue
	fi

	name="$(basename "$zip")"
	manifest="$WORK_DIR/$name.manifest"
	unzip -Z1 "$zip" > "$manifest"

	# Every distribution zip unpacks to a single plugin directory (mcpwp/).
	root="$(head -n1 "$manifest" | cut -d/ -f1)"
	if [[ -z "$root" ]]; then
		echo "FAIL: $name is empty" >&2
		FAILED=1
		continue
	fi

	extract="$WORK_DIR/$name.d"
	mkdir -p "$extract"
	unzip -q "$zip" "$root/*.php" -d "$extract" 2>/dev/null || true

	refs=0
	missing=0
	while IFS= read -r -d '' php; do
		while IFS= read -r asset; do
			[[ -n "$asset" ]] || continue
			refs=$((refs + 1))
			if ! grep -qxF "$root/$asset" "$manifest"; then
				echo "FAIL: $name: ${php#"$extract"/} references $asset, which is not in the zip" >&2
				missing=1
			fi
		done < <(grep -ohP "MCPWP_PLUGIN_DIR\s*\.\s*'\K[^']+\.(?:png|svg|jpe?g|gif|webp)(?=')" "$php" 2>/dev/null | sort -u || true)
	done < <(find "$extract" -type f -name '*.php' -print0)

	if [[ "$missing" -eq 1 ]]; then
		FAILED=1
	else
		echo "OK: $name ($refs image reference(s), all shipped)"
	fi
done

if [[ "$FAILED" -eq 1 ]]; then
	echo "" >&2
	echo "A shipped file points at an asset its build strips. Keep the asset in that" >&2
	echo "build (see the asset-stripping block in mcpwp/scripts/build-*.sh and the" >&2
	echo "/assets/* lines in mcpwp/.distignore) or stop referencing it." >&2
	exit 1
fi
