#!/usr/bin/env bash
# Fail if shipped code references a class from a file that .distignore prunes,
# without guarding on class_exists().
#
# Distribution builds are not the tree. `.distignore` removes files per build —
# class-mcpwp-updater.php is dropped from the Freemius build, where the license
# provider owns updates. Code that calls such a class unguarded is a fatal on
# every install of that build, and nothing else catches it: unit tests load the
# full tree, PHP lint only parses one file at a time, and CI never builds the
# pruned variant and exercises it.
#
# That is exactly how #723 shipped — 3.8.0 refactored the /update controller
# onto Mcpwp_Updater, which does not exist in the Freemius build, and every
# premium site's /update endpoint returned a 500 until someone called it.
#
# This is a tripwire, not a proof: it asserts the guard is present in the file,
# not that every individual call site sits behind it. Cheap, and it would have
# caught #723.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/mcpwp"
DISTIGNORE="$PLUGIN_DIR/.distignore"

[[ -f "$DISTIGNORE" ]] || { echo "No .distignore at $DISTIGNORE" >&2; exit 1; }

# A path is pruned if .distignore names it outright or names a directory above
# it. Without the directory case, everything under /tests reads as shipped code.
is_pruned() {
	local path="$1" entry rel
	while IFS= read -r entry; do
		[[ -n "$entry" && "$entry" != \#* ]] || continue
		rel="${entry#/}"
		[[ -n "$rel" ]] || continue
		if [[ "$path" == "$rel" || "$path" == "$rel"/* ]]; then
			return 0
		fi
	done < "$DISTIGNORE"
	return 1
}

FAILED=0

while IFS= read -r entry; do
	# Only individual PHP files matter — a pruned directory's classes are not
	# referenced by name from shipped code in this plugin.
	[[ "$entry" == *.php ]] || continue

	rel="${entry#/}"
	pruned_file="$PLUGIN_DIR/$rel"
	[[ -f "$pruned_file" ]] || continue

	# Class names this pruned file defines.
	while IFS= read -r class; do
		[[ -n "$class" ]] || continue

		# Every shipped PHP file that names the class.
		while IFS= read -r hit; do
			[[ -n "$hit" ]] || continue
			[[ "$hit" != "$pruned_file" ]] || continue

			# Skip files that are themselves pruned — either named outright or
			# living under a pruned directory (/tests, /scripts, ...).
			hit_rel="${hit#"$PLUGIN_DIR"/}"
			if is_pruned "$hit_rel"; then
				continue
			fi

			if ! grep -q "class_exists( *['\"]${class}['\"]" "$hit"; then
				echo "FAIL: $hit_rel references ${class} but never guards on class_exists('${class}')" >&2
				echo "      ${class} is pruned by .distignore (/$rel) — this is a fatal in that build." >&2
				FAILED=1
			fi
		done < <(grep -rlE "(${class}::|new +${class}\b)" "$PLUGIN_DIR" --include='*.php' 2>/dev/null || true)
	done < <(grep -oP '^\s*(?:final\s+|abstract\s+)?class\s+\K\w+' "$pruned_file" 2>/dev/null || true)
done < "$DISTIGNORE"

if [[ "$FAILED" -eq 1 ]]; then
	echo "" >&2
	echo "Guard pruned-file classes with class_exists() before use, or stop pruning the file." >&2
	exit 1
fi

echo "OK: no unguarded references to .distignore-pruned classes"
