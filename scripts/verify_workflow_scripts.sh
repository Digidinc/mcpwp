#!/usr/bin/env bash
#
# Every scripts/… path a workflow invokes must be tracked by git.
#
# This is the defect #734 actually had: verify_test_discovery.sh was referenced
# by CI and never pushed, so the step failed with exit 127 — "No such file or
# directory" — on a green-looking branch. The response at the time was to
# invert .gitignore so shell scripts are tracked by default, which fixed that
# symptom and opened a credential-commit path in its place (#738).
#
# This closes the original defect at its own chokepoint instead: a workflow that
# names a file git does not have fails here, loudly, whatever the ignore rules
# happen to say. It also keeps working if the ignore rules are ever changed
# back.
#
# Exit codes
#   0  every referenced script is tracked
#   1  a referenced script is missing from the index
#   2  could not check (no workflows found, not a git repo, bad environment)
#
# "Could not check" is never 0. A scan that did not run and a scan that found
# nothing must not report the same thing.

set -uo pipefail

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || {
	echo "verify_workflow_scripts: not inside a git repository." >&2
	exit 2
}

cd "$repo_root" || exit 2

workflow_dir=".github/workflows"

if [ ! -d "$workflow_dir" ]; then
	echo "verify_workflow_scripts: no $workflow_dir directory — nothing could be checked." >&2
	exit 2
fi

workflows=$(find "$workflow_dir" -maxdepth 1 -type f \( -name '*.yml' -o -name '*.yaml' \) | sort)

if [ -z "$workflows" ]; then
	echo "verify_workflow_scripts: $workflow_dir contains no workflow files — nothing could be checked." >&2
	exit 2
fi

# Every scripts/… file any workflow mentions. Deliberately greedy about where
# the reference appears (bash …, sh …, ./…, a bare path in a run block): a
# reference this misses is a reference this cannot protect.
#
# An executable extension is required, and that is load-bearing rather than
# tidy. Workflows also mention scripts/ in globs — `mcpwp/scripts/mcpwp-*.zip`
# for the release upload — and a pattern that stops at the first character it
# does not recognise turns that into the path `mcpwp/scripts/mcpwp-`, which of
# course is not tracked. A checker whose first run is a false positive is a
# checker people learn to skip.
referenced=$(
	grep -rhoE '(mcpwp/)?scripts/[A-Za-z0-9_./-]+\.(sh|bash|mjs|cjs|js|php|py)\b' $workflows 2>/dev/null \
		| sort -u
)

if [ -z "$referenced" ]; then
	echo "OK: no workflow references a scripts/ path."
	exit 0
fi

missing=0
checked=0

while IFS= read -r path; do
	[ -n "$path" ] || continue
	checked=$((checked + 1))

	# Asked per path rather than by grepping one big `git ls-files` dump.
	# Observed while writing this: in a working tree shared with other
	# processes, a concurrent git operation can make the bulk listing come back
	# short WITHOUT a non-zero exit, and every path it happened to omit then
	# reads as "not tracked". That is this script's own failure mode — an
	# unavailable answer and a negative answer returning the same thing — and
	# it produced a false MISSING on a file that was tracked all along.
	#
	# --error-unmatch gives three distinguishable outcomes instead of two:
	# 0 tracked, 1 genuinely untracked, anything else could-not-ask.
	git ls-files --error-unmatch -- "$path" >/dev/null 2>&1
	status=$?

	if [ "$status" -eq 0 ]; then
		continue
	fi

	if [ "$status" -ne 1 ]; then
		echo "verify_workflow_scripts: git could not answer for '$path' (exit $status) — could not check." >&2
		exit 2
	fi

	missing=$((missing + 1))
	echo "MISSING: $workflow_dir references '$path', which git does not track."

	if [ -e "$path" ]; then
		echo "         The file exists locally but was never committed — that is exactly the #734 shape."
		if ignored_by=$(git check-ignore -v "$path" 2>/dev/null); then
			echo "         Ignored by: $ignored_by"
		fi
	else
		echo "         The file does not exist here either. Fix the reference or add the script."
	fi
done <<< "$referenced"

if [ "$missing" -gt 0 ]; then
	echo
	echo "FAIL: $missing of $checked workflow-referenced script path(s) are not tracked."
	exit 1
fi

echo "OK: all $checked workflow-referenced script path(s) are tracked by git."
exit 0
