#!/usr/bin/env bash
# Fail if a built plugin zip ships PHP that points at an image file the zip
# does not contain.
#
# #1188: WP.org icon files (assets/icon-128x128.png) were stripped from builds
# while Freemius still referenced them, so premium opt-in screens showed a broken
# image. Later builds also pin approved admin marks (SVG + 256px PNG) and scan
# every PHP file for image paths that must exist in the same zip.
#
# This check reads the zip, not the tree. It scans every shipped PHP file for a
# literal image path (png, svg, jpg, jpeg, gif, webp; any case; either quote
# style) built as
#
#   MCPWP_PLUGIN_DIR . '<path>'      relative to the plugin root
#   MCPWP_PLUGIN_URL . '<path>'      relative to the plugin root
#   plugins_url( '<path>', __FILE__ )  relative to the calling file's directory
#   plugins_url( '<path>', <other> )   relative to the plugin root (main file)
#   plugins_url( '<plugin>/<path>' )   relative to wp-content/plugins
#
# and requires <path> to be in the same zip. Builds that drop freemius-init.php
# (free, self-hosted, agency) pass because nothing they ship names the icon.
#
# It fails closed: a zip that lists PHP files but extracts none, or a grep that
# errors (rather than finding nothing), is a failure, not a pass. Only POSIX
# grep -E/-o/-i is used, so it behaves the same on GNU and BSD/macOS.
#
# Usage:
#   bash scripts/verify_plugin_assets.sh <zip> [<zip> ...]

set -euo pipefail

if [[ $# -eq 0 ]]; then
	echo "Usage: $0 <zip> [<zip> ...]" >&2
	exit 2
fi

command -v unzip >/dev/null 2>&1 || { echo "unzip is required" >&2; exit 1; }

sha256_digest() {
	if command -v sha256sum >/dev/null 2>&1 && printf 'x' | sha256sum >/dev/null 2>&1; then
		sha256sum | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 | awk '{print $1}'
	else
		echo "FAIL: sha256sum or shasum -a 256 is required" >&2
		return 1
	fi
}

EXT='(png|svg|jpe?g|gif|webp)'
Q="['\"]"
NQ="[^'\"]"
CONST_RE="MCPWP_PLUGIN_(DIR|URL)[[:space:]]*\\.[[:space:]]*${Q}${NQ}+\\.${EXT}${Q}"
PURL_RE="plugins_url[[:space:]]*\\([[:space:]]*${Q}${NQ}+\\.${EXT}${Q}[[:space:]]*(,[[:space:]]*[^)]*)?\\)"

# grep exits 1 for "no match", 2+ for an error. Only the latter is a failure.
grep_or_fail() {
	local status=0
	grep "$@" || status=$?
	if [[ "$status" -gt 1 ]]; then
		echo "FAIL: grep $* exited $status" >&2
		return 1
	fi
	return 0
}

# Self-test the patterns once, so an unsupported grep fails here loudly instead
# of matching nothing and passing every zip.
selftest="MCPWP_PLUGIN_DIR . 'a/b.PNG'; plugins_url( \"c.svg\", __FILE__ );"
if [[ "$(printf '%s\n' "$selftest" | grep -oiE "$CONST_RE|$PURL_RE" | wc -l | tr -d ' ')" != "2" ]]; then
	echo "FAIL: this grep does not support the -o -i -E patterns verify_plugin_assets.sh needs" >&2
	exit 1
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

FAILED=0

MARK_SVG_REL="assets/mcpwp-mark-ochre.svg"
MARK_PNG_REL="assets/mcpwp-mark-ochre-256.png"
MARK_SVG_SHA="d104a414a9d8538536a61a9add8c72f6edb2ec0f4d2479f284a04fe3c512d42a"
MARK_PNG_SHA="1891372b32a9cef2a69f3f4398f9d0d70b246210b64a07e9733d2937e08173bf"

# Print the path a matched expression resolves to, relative to the plugin root.
# $1 = matched text, $2 = directory of the PHP file relative to the plugin root.
resolve_ref() {
	local match="$1" php_dir="$2" path second
	path="$(printf '%s' "$match" | sed -E "s/^[^'\"]*['\"]([^'\"]+)['\"].*$/\\1/")"
	path="${path#/}"
	case "$match" in
		[Pp][Ll][Uu][Gg][Ii][Nn][Ss]_[Uu][Rr][Ll]*)
			second="$(printf '%s' "$match" | sed -E "s/^[^'\"]*['\"][^'\"]+['\"][[:space:]]*,?[[:space:]]*([^)]*)\\).*$/\\1/" | tr -d '[:space:]')"
			if [[ -z "$second" ]]; then
				# Relative to wp-content/plugins: drop the plugin directory name.
				path="${path#*/}"
			elif [[ "$second" == "__FILE__" && -n "$php_dir" && "$php_dir" != "." ]]; then
				path="$php_dir/$path"
			fi
			;;
	esac
	printf '%s\n' "$path"
}

for zip in "$@"; do
	if [[ ! -f "$zip" ]]; then
		echo "FAIL: $zip does not exist" >&2
		FAILED=1
		continue
	fi

	name="$(basename "$zip")"
	manifest="$WORK_DIR/$name.manifest"
	if ! unzip -Z1 "$zip" > "$manifest"; then
		echo "FAIL: $name: cannot list zip contents" >&2
		FAILED=1
		continue
	fi

	# Every distribution zip unpacks to a single plugin directory (mcpwp/).
	root="$(head -n1 "$manifest" | cut -d/ -f1)"
	if [[ -z "$root" ]]; then
		echo "FAIL: $name is empty" >&2
		FAILED=1
		continue
	fi
	roots="$(cut -d/ -f1 "$manifest" | sort -u | wc -l | tr -d ' ')"
	if [[ "$roots" -ne 1 ]]; then
		echo "FAIL: $name has $roots top-level entries; a plugin zip unpacks to one directory" >&2
		FAILED=1
		continue
	fi

	listed_php="$(grep -c '\.php$' "$manifest" || true)"
	extract="$WORK_DIR/$name.d"
	mkdir -p "$extract"
	if [[ "$listed_php" -gt 0 ]] && ! unzip -q "$zip" "$root/*.php" -d "$extract"; then
		echo "FAIL: $name: could not extract its PHP files" >&2
		FAILED=1
		continue
	fi

	extracted_php="$(find "$extract" -type f -name '*.php' | wc -l | tr -d ' ')"
	if [[ "$listed_php" -gt 0 && "$extracted_php" -eq 0 ]]; then
		echo "FAIL: $name lists $listed_php PHP file(s) but none were extracted; nothing was checked" >&2
		FAILED=1
		continue
	fi

	refs=0
	missing=0
	while IFS= read -r -d '' php; do
		php_rel="${php#"$extract/$root"/}"
		php_dir="$(dirname "$php_rel")"
		matches="$WORK_DIR/matches"
		grep_or_fail -oiE "$CONST_RE|$PURL_RE" "$php" > "$matches" || { FAILED=1; continue; }
		while IFS= read -r match; do
			[[ -n "$match" ]] || continue
			asset="$(resolve_ref "$match" "$php_dir")"
			refs=$((refs + 1))
			if ! grep -qxF "$root/$asset" "$manifest"; then
				echo "FAIL: $name: $php_rel references $asset, which is not in the zip" >&2
				missing=1
			fi
		done < "$matches"
	done < <(find "$extract" -type f -name '*.php' -print0)

	if [[ "$missing" -eq 1 ]]; then
		FAILED=1
	else
		echo "OK: $name ($extracted_php PHP file(s), $refs image reference(s), all shipped)"
	fi

	mark_failed=0
	for mark_rel in "$MARK_SVG_REL" "$MARK_PNG_REL"; do
		mark_path="$root/$mark_rel"
		if ! grep -qxF "$mark_path" "$manifest"; then
			echo "FAIL: $name: required brand mark $mark_rel is not in the zip" >&2
			mark_failed=1
			continue
		fi
		want_sha="$MARK_SVG_SHA"
		[[ "$mark_rel" == "$MARK_PNG_REL" ]] && want_sha="$MARK_PNG_SHA"
		got_sha="$(unzip -p "$zip" "$mark_path" | sha256_digest)"
		if [[ "$got_sha" != "$want_sha" ]]; then
			echo "FAIL: $name: $mark_rel sha256 is $got_sha (want $want_sha)" >&2
			mark_failed=1
		fi
	done
	if [[ "$mark_failed" -eq 1 ]]; then
		FAILED=1
	else
		echo "OK: $name (approved admin brand marks present with expected sha256)"
	fi
done

if [[ "$FAILED" -eq 1 ]]; then
	echo "" >&2
	echo "A shipped file points at an asset its build strips, or the zip could not" >&2
	echo "be checked. Keep the asset in that build (see the asset-stripping block in" >&2
	echo "mcpwp/scripts/build-*.sh and the /assets/* lines in mcpwp/.distignore) or" >&2
	echo "stop referencing it." >&2
	exit 1
fi
