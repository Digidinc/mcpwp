#!/usr/bin/env bash
#
# A tracked script must read its credentials from the environment, never carry
# them.
#
# #734 inverted .gitignore so shell scripts are tracked by default, which fixed
# a real bug — CI invoking a script nobody had pushed — and opened a worse one
# (#738). `.sh` is exactly where credentials live: CLAUDE.md itself documents
# `FREEMIUS_BEARER_TOKEN=… bash scripts/release_freemius.sh`, so
# `export TOKEN=…` in a local helper is the expected shape, and that helper is
# now tracked by default.
#
# The original note called that "visible in review — harmless". Both halves are
# wrong. An agent running `git add -A` commits it with no human in the loop —
# that is how .wrangler account state reached a published ZIP in 3.10.0/3.10.1 —
# and a leaked credential is not recoverable by reverting.
#
# So the default stays (a CI-referenced script cannot silently vanish) and the
# leak path is closed here instead.
#
# What is flagged: a secret-shaped NAME assigned a LITERAL. What is not: the
# same name assigned from $VAR, ${VAR}, $(command), a prompt, or left empty —
# every legitimate form in the scripts this repo already ships.
#
# Escape hatch, deliberately narrow: put `# secret-scan: allow <reason>` on the
# line itself or the line before. A reason is required, because "allow" with no
# argument is how a scanner becomes decorative.
#
# Exit codes
#   0  no findings
#   1  a tracked file carries what looks like a credential
#   2  could not scan
#
# "Could not scan" is never 0.

set -uo pipefail

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || {
	echo "verify_no_committed_secrets: not inside a git repository." >&2
	exit 2
}

cd "$repo_root" || exit 2

# Tracked files only: an untracked local helper is not the threat, a committed
# one is. Scoped to the places a credential would plausibly be typed.
files=$(git ls-files -- 'scripts/*' 'mcpwp/scripts/*' '*.sh' 2>/dev/null | sort -u)
ls_status=$?

if [ "$ls_status" -ne 0 ]; then
	echo "verify_no_committed_secrets: git ls-files failed (exit $ls_status) — could not scan." >&2
	exit 2
fi

if [ -z "$files" ]; then
	echo "verify_no_committed_secrets: no tracked files matched the scan scope — could not scan." >&2
	exit 2
fi

# A name that means "this is a credential", anywhere inside the variable name.
# The prefix wildcard is the point: the real names in this repo are
# FREEMIUS_BEARER_TOKEN and CF_API_TOKEN, not bare TOKEN, and a pattern
# anchored to the start of the name catches neither.
assignment="(^|[[:space:];({]|export +)[A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|API_?KEY|APIKEY|BEARER|PRIVATE_?KEY|CREDENTIAL|ACCESS_KEY)[A-Za-z0-9_]*="

# The value is read from somewhere rather than written down: \$VAR, \${VAR},
# \$(cmd), \`cmd\`. Every legitimate use in this repo has this shape.
from_environment="${assignment}['\"]?(\\\$|\`)"

# The value is written down: eight or more credential-ish characters.
literal_value="${assignment}['\"]?[A-Za-z0-9/+_.~-]{8,}"

# ...but it is obviously a placeholder in usage text, not a credential.
#
# Handled as an exclusion rather than by annotating each line, because these
# live inside `cat <<EOF` usage blocks that the script PRINTS: a
# `# secret-scan: allow` comment on or above such a line is shell text there,
# and would be shown to the user running --help. A scanner is not allowed to
# make the tool's own output worse.
placeholder="(x{3,}|YOUR[_-]|CHANGE[_-]?ME|EXAMPLE|PLACEHOLDER|REDACTED|\\.\\.\\.|<|>)"

findings=0
scanned=0

while IFS= read -r file; do
	[ -n "$file" ] || continue
	[ -f "$file" ] || continue

	# Skip this scanner and its own test fixtures — they contain the patterns
	# by construction.
	case "$file" in
		scripts/verify_no_committed_secrets.sh) continue ;;
	esac

	scanned=$((scanned + 1))
	previous_line=''
	lineno=0

	while IFS= read -r line || [ -n "$line" ]; do
		lineno=$((lineno + 1))
		current_line="$line"

		if printf '%s' "$line" | grep -qE '# *secret-scan: *allow +\S' \
			|| printf '%s' "$previous_line" | grep -qE '# *secret-scan: *allow +\S'; then
			previous_line="$current_line"
			continue
		fi

		reason=''

		# A PEM private key body has no legitimate reason to be committed.
		if printf '%s' "$line" | grep -qE -- '-----BEGIN [A-Z ]*PRIVATE KEY-----'; then
			reason='PEM private key header'
		# NAME=<literal>. Assignments from $VAR, ${VAR}, $(...), `...` or empty
		# are the correct shape and are not flagged.
		elif printf '%s' "$line" | grep -qiE "$literal_value"; then
			if ! printf '%s' "$line" | grep -qiE "$from_environment" \
				&& ! printf '%s' "$line" | grep -qiE "${assignment}['\"]?[A-Za-z0-9/+_.~-]*${placeholder}"; then
				reason='credential-shaped name assigned a literal'
			fi
		fi

		if [ -n "$reason" ]; then
			findings=$((findings + 1))
			echo "SECRET?: $file:$lineno — $reason"
			echo "         $(printf '%s' "$line" | cut -c1-80 | sed 's/[A-Za-z0-9]\{6,\}/<redacted>/2g')"
		fi

		previous_line="$current_line"
	done < "$file"
done <<< "$files"

if [ "$findings" -gt 0 ]; then
	echo
	echo "FAIL: $findings possible credential(s) in $scanned tracked file(s)."
	echo "Read it from the environment instead, or annotate the line with"
	echo "'# secret-scan: allow <reason>' if it genuinely is not a credential."
	exit 1
fi

echo "OK: no credential-shaped literals in $scanned tracked file(s)."
exit 0
