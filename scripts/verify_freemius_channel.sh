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
# A "released" flag is not a working download. For the verified tag this also
# fetches the premium ZIP the way the updater does — the API answers with a
# redirect to signed storage, which is fetched WITHOUT the bearer header
# (forwarding it makes S3 answer a misleading SignatureDoesNotMatch 403) — and
# fails unless the archive is valid, carries mcpwp-premium/mcpwp.php with the
# expected Version: header and MCPWP_VERSION constant, and ships
# mcpwp-premium/freemius/start.php. When --expect-version names an older tag
# that is still released, both it and the latest build are verified: the
# latest is what the updater serves.
#
# Usage:
#   scripts/verify_freemius_channel.sh [--expect-version X.Y.Z] [--product-id ID]
#                                      [--retries N] [--delay S] [--strict-storage]
#
# Exit codes:
#   0  Freemius delivers a valid build (storage refusals, if any, are WARNed)
#   1  it does not, or it could not be checked
#   2  --strict-storage: delivered, but storage refused a request first — an
#      updater that does not retry would have failed
#
# Contract test: scripts/tests/verify_freemius_channel_test.sh
#
# Auth: FREEMIUS_BEARER_TOKEN in the environment.

set -uo pipefail

PRODUCT_ID="${FREEMIUS_PRODUCT_ID:-23824}"
EXPECT_VERSION=""
RETRIES=1
DELAY=10
STRICT_STORAGE=""
STORAGE_REFUSALS=0

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
	--strict-storage)
		STRICT_STORAGE=1
		shift
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

for tool in curl jq unzip; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "FAIL: $tool is required to verify the paid channel and is not installed." >&2
		exit 1
	fi
done

TMP_DIR="$(mktemp -d)" || {
	echo "FAIL: could not create a temp directory for the premium ZIP." >&2
	exit 1
}
trap 'rm -rf "$TMP_DIR"' EXIT

# Download the premium ZIP for one released tag and prove it is the build it
# claims to be. Prints no credential and no signed-URL query string.
verify_premium_zip() {
	local tags_json="$1" version="$2"

	local tag_id
	tag_id="$(echo "$tags_json" |
		jq -r --arg v "$version" '[.tags[] | select(.release_mode == "released" and .version == $v) | .id | tonumber] | max // empty' 2>/dev/null)"
	[[ -n "$tag_id" ]] || fail "no released tag id found for $version" || return 1
	echo "  premium ZIP:     tag $tag_id ($version)"

	local headers="$TMP_DIR/headers" zip="$TMP_DIR/premium.zip"
	rm -f "$headers" "$zip"

	# Step 1: the API, with the bearer header, redirects NOT followed.
	# Step 2: the signed storage URL it redirects to, with NO Authorization
	# header. Storage intermittently answers 403 SignatureDoesNotMatch for a
	# valid signed URL (some frontends refuse what others serve), so step 2
	# gets a few attempts, each with a fresh redirect — and every refusal is
	# printed, because an updater that does not retry fails the same way.
	local attempt=1 max_attempts=3 fetched="" api_status storage_status location s3_code
	while [[ "$attempt" -le "$max_attempts" ]]; do
		rm -f "$headers" "$zip"
		api_status="$(curl -sS --max-time 60 -D "$headers" -o "$zip" -w '%{http_code}' \
			-H "Authorization: Bearer ${FREEMIUS_BEARER_TOKEN}" \
			"https://api.freemius.com/v1/products/${PRODUCT_ID}/tags/${tag_id}.zip?is_premium=true" 2>/dev/null)" ||
			fail "could not reach the Freemius download endpoint for tag $tag_id" || return 1

		if [[ "$api_status" == "200" ]]; then
			fetched=1
			break
		fi
		[[ "$api_status" =~ ^30[1278]$ ]] ||
			fail "Freemius answered HTTP $api_status for the $version premium download" || return 1

		location="$(grep -i '^location:' "$headers" | tail -1 | sed -E 's/^[Ll][Oo][Cc][Aa][Tt][Ii][Oo][Nn]:[[:space:]]*//' | tr -d '\r')"
		[[ "$location" == https://* ]] ||
			fail "Freemius redirected the premium download ($api_status) without an https Location" || return 1
		[[ "$attempt" -eq 1 ]] && echo "  storage:         ${location%%\?*}"

		rm -f "$zip"
		storage_status="$(curl -sS --max-time 300 --proto '=https' -o "$zip" -w '%{http_code}' "$location" 2>/dev/null)"
		if [[ "$storage_status" == "200" ]]; then
			fetched=1
			break
		fi

		# Only the S3 error code is printed: the rest of an S3 error body
		# echoes the signed request.
		s3_code="$(grep -ao '<Code>[^<]*</Code>' "$zip" 2>/dev/null | head -1 | sed 's/<[^>]*>//g')"
		echo "  WARN: storage answered HTTP ${storage_status:-000}${s3_code:+ ($s3_code)} for the $version premium ZIP (attempt $attempt/$max_attempts)" >&2
		STORAGE_REFUSALS=$((STORAGE_REFUSALS + 1))
		attempt=$((attempt + 1))
	done

	[[ -n "$fetched" ]] ||
		fail "storage refused the $version premium ZIP on all $max_attempts attempts — paying customers cannot download it" || return 1
	[[ "$attempt" -gt 1 ]] &&
		echo "  WARN: the premium ZIP downloaded only on attempt $attempt/$max_attempts — storage is refusing some requests" >&2

	unzip -tq "$zip" >/dev/null 2>&1 ||
		fail "the $version premium download is not a valid ZIP" || return 1

	local listing
	listing="$(unzip -Z1 "$zip" 2>/dev/null)"
	grep -qx 'mcpwp-premium/mcpwp.php' <<<"$listing" ||
		fail "the $version premium ZIP has no mcpwp-premium/mcpwp.php" || return 1
	grep -qx 'mcpwp-premium/freemius/start.php' <<<"$listing" ||
		fail "the $version premium ZIP has no mcpwp-premium/freemius/start.php — the Freemius SDK is missing" || return 1

	local zip_version
	zip_version="$(unzip -p "$zip" mcpwp-premium/mcpwp.php 2>/dev/null |
		grep -m1 -E '^[[:space:]/*#@]*Version:' |
		sed -E 's/^[[:space:]/*#@]*Version:[[:space:]]*//' | tr -d '\r[:space:]')"
	[[ "$zip_version" == "$version" ]] ||
		fail "the premium ZIP's mcpwp.php says Version: ${zip_version:-<none>}, expected $version" || return 1

	# The header and the constant drift apart (the version-drift bug class):
	# the runtime reports MCPWP_VERSION, so both must match.
	local zip_constant
	zip_constant="$(unzip -p "$zip" mcpwp-premium/mcpwp.php 2>/dev/null |
		grep -m1 -E "define\([[:space:]]*['\"]MCPWP_VERSION['\"]" |
		sed -E "s/.*define\([[:space:]]*['\"]MCPWP_VERSION['\"][[:space:]]*,[[:space:]]*['\"]([^'\"]*)['\"].*/\1/")"
	[[ "$zip_constant" == "$version" ]] ||
		fail "the premium ZIP's MCPWP_VERSION is ${zip_constant:-<none>}, expected $version (its Version: header says $zip_version)" || return 1

	echo "  OK: premium ZIP is a valid $version build with the Freemius SDK"
	return 0
}

check_once() {
	STORAGE_REFUSALS=0
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
			verify_premium_zip "$response" "$EXPECT_VERSION" || return 1
			echo "  OK: Freemius can deliver $EXPECT_VERSION"
			return 0
		fi

		# Newer than expected is fine — a later release already shipped.
		# Older is the failure this script exists to catch.
		local newest
		newest="$(printf '%s\n%s\n' "$latest" "$EXPECT_VERSION" | sort -V | tail -1)"
		if [[ "$newest" == "$latest" ]]; then
			# The expected build when it is still a released tag, and always
			# the latest: that is the build the updater actually serves.
			if echo "$response" | jq -e --arg v "$EXPECT_VERSION" 'any(.tags[]; .release_mode == "released" and .version == $v)' >/dev/null 2>&1; then
				verify_premium_zip "$response" "$EXPECT_VERSION" || return 1
			fi
			verify_premium_zip "$response" "$latest" || return 1
			echo "  OK: Freemius is on $latest, ahead of $EXPECT_VERSION"
			return 0
		fi

		fail "Freemius serves $latest but $EXPECT_VERSION was expected — paying customers cannot get it" || return 1
	fi

	verify_premium_zip "$response" "$latest" || return 1
	echo "  OK: Freemius has a released tag with a working premium ZIP"
	return 0
}

attempt=1
while true; do
	if check_once; then
		if [[ -n "$STRICT_STORAGE" && "$STORAGE_REFUSALS" -gt 0 ]]; then
			echo "FAIL (--strict-storage): storage refused $STORAGE_REFUSALS request(s) before delivering — an updater that does not retry would have failed." >&2
			exit 2
		fi
		exit 0
	fi
	if [[ "$attempt" -ge "$RETRIES" ]]; then
		exit 1
	fi
	echo "  retrying in ${DELAY}s ($attempt/$RETRIES)" >&2
	sleep "$DELAY"
	attempt=$((attempt + 1))
done
