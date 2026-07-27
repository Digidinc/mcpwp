#!/usr/bin/env bash
# Prove the Freemius channel can deliver a given version to paying customers.
#
# Freemius is the update channel for the premium build — a separate path from
# updates.mcpwp.net, with its own way of going quiet. Publishing to it was a
# manual step for a long time, and on 2026-07-27 it turned out to have been
# stuck on 3.7.0 since 2026-07-21: 3.7.1, 3.7.2, 3.8.0 and 3.9.0 never reached
# a paying customer, including the #716 updater fix. Nothing noticed, because
# nothing looked.
#
# Same contract as verify_update_channel.sh, for the other half of the fleet.
#
# Usage:
#   scripts/verify_freemius_channel.sh [--expect-version X.Y.Z] [--product-id ID]
#
# Auth: FREEMIUS_BEARER_TOKEN in the environment.

set -uo pipefail

PRODUCT_ID="${FREEMIUS_PRODUCT_ID:-23824}"
EXPECT_VERSION=""
RETRIES=1
DELAY=10

while [[ $# -gt 0 ]]; do
	case "$1" in
	--expect-version)
		EXPECT_VERSION="${2:-}"
		shift 2
		;;
	--product-id)
		PRODUCT_ID="${2:-}"
		shift 2
		;;
	--retries)
		RETRIES="${2:-1}"
		shift 2
		;;
	--delay)
		DELAY="${2:-10}"
		shift 2
		;;
	-h | --help)
		grep '^#' "$0" | sed 's/^# \{0,1\}//'
		exit 0
		;;
	*)
		echo "Unknown option: $1" >&2
		exit 1
		;;
	esac
done

fail() {
	echo "  FAIL: $1" >&2
	return 1
}

# A missing token is a failure, not a skip. "We could not check the paid
# channel" and "the paid channel is fine" must never print the same thing —
# that equivalence is what let Freemius sit four releases behind.
if [[ -z "${FREEMIUS_BEARER_TOKEN:-}" ]]; then
	echo "FAIL: FREEMIUS_BEARER_TOKEN is not set — the paid channel cannot be verified." >&2
	echo "      Set it so this check can run; an unverifiable channel is not a passing one." >&2
	exit 1
fi

check_once() {
	echo "→ Freemius product: $PRODUCT_ID"

	local response
	response="$(curl -sS --max-time 60 \
		-H "Authorization: Bearer ${FREEMIUS_BEARER_TOKEN}" \
		"https://api.freemius.com/v1/products/${PRODUCT_ID}/tags.json?count=25" 2>&1)" ||
		fail "could not reach the Freemius API" || return 1

	echo "$response" | jq -e '.tags' >/dev/null 2>&1 ||
		fail "Freemius API returned no tag list: $(echo "$response" | head -c 200)" || return 1

	# Only "released" tags reach customers. A build sitting in pending/beta is
	# not shipped, and counting it would report a release nobody can install.
	local latest
	latest="$(echo "$response" |
		jq -r '[.tags[] | select(.release_mode == "released") | .version] | .[]' 2>/dev/null |
		sort -V | tail -1)"

	[[ -n "$latest" ]] || fail "no released tag found on Freemius" || return 1
	echo "  latest released: $latest"

	if [[ -n "$EXPECT_VERSION" ]]; then
		echo "  expected:        $EXPECT_VERSION"

		if [[ "$latest" == "$EXPECT_VERSION" ]]; then
			echo "  OK: Freemius can deliver $EXPECT_VERSION"
			return 0
		fi

		# Newer than expected is fine — a later release already shipped.
		# Older is the failure this script exists to catch.
		local newest
		newest="$(printf '%s\n%s\n' "$latest" "$EXPECT_VERSION" | sort -V | tail -1)"
		if [[ "$newest" == "$latest" ]]; then
			echo "  OK: Freemius is on $latest, ahead of $EXPECT_VERSION"
			return 0
		fi

		fail "Freemius serves $latest but $EXPECT_VERSION was expected — paying customers cannot get it" || return 1
	fi

	echo "  OK: Freemius has a released tag"
	return 0
}

attempt=1
while true; do
	if check_once; then
		exit 0
	fi
	if [[ "$attempt" -ge "$RETRIES" ]]; then
		exit 1
	fi
	echo "  retrying in ${DELAY}s ($attempt/$RETRIES)" >&2
	sleep "$DELAY"
	attempt=$((attempt + 1))
done
