#!/usr/bin/env bash
#
# Verify that a published update channel can actually deliver an update.
#
# Every check here exists because the corresponding failure has been silent in
# the past: a manifest that advertises a version the served ZIP does not
# contain, a download_url pointing at a key that was never uploaded, a channel
# that quietly rots after a partial publish. Publishing without this check is
# how #716 stayed invisible — tagging is not shipping.
#
# Exits non-zero on the first failed check. That is the point: callers
# (publish workflow, canary cron) must fail loudly.
#
# Usage:
#   scripts/verify_update_channel.sh --manifest-url URL [options]
#
# Options:
#   --manifest-url URL     Manifest to verify (required)
#   --expect-version VER   Require the manifest to advertise exactly VER
#   --retries N            Attempts before giving up (default 1)
#   --delay S              Seconds between attempts (default 20)
#   -h, --help             Show help

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MANIFEST_URL=""
EXPECT_VERSION=""
RETRIES=1
DELAY=20

usage() {
	sed -n '3,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
	case "$1" in
	--manifest-url)
		MANIFEST_URL="${2:-}"
		shift 2
		;;
	--expect-version)
		EXPECT_VERSION="${2:-}"
		shift 2
		;;
	--retries)
		RETRIES="${2:-1}"
		shift 2
		;;
	--delay)
		DELAY="${2:-20}"
		shift 2
		;;
	-h | --help)
		usage
		exit 0
		;;
	*)
		echo "Unknown argument: $1" >&2
		usage >&2
		exit 2
		;;
	esac
done

if [[ -z "$MANIFEST_URL" ]]; then
	echo "ERROR: --manifest-url is required" >&2
	usage >&2
	exit 2
fi

for cmd in curl python3 unzip; do
	command -v "$cmd" >/dev/null 2>&1 || {
		echo "ERROR: missing required command: $cmd" >&2
		exit 2
	}
done

# Keep in sync with Mcpwp_Security::is_trusted_package_url(). A manifest whose
# download_url falls outside this list is one every site will refuse to install
# from — the channel is broken even though every URL resolves.
TRUSTED_HOSTS=("updates.mcpwp.net" "mumega.com")

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

fail() {
	echo "  FAIL: $*" >&2
	return 1
}

verify_once() {
	local manifest_file="$WORK_DIR/version.json"
	local zip_file="$WORK_DIR/package.zip"
	rm -f "$manifest_file" "$zip_file"

	echo "→ manifest: $MANIFEST_URL"

	local manifest_status
	manifest_status="$(curl -sS -L -o "$manifest_file" -w '%{http_code}' "$MANIFEST_URL" 2>/dev/null || echo "000")"
	[[ "$manifest_status" == "200" ]] || fail "manifest returned HTTP $manifest_status" || return 1

	# Parse and structurally validate in one pass. The updater rejects a
	# manifest missing either field, so a channel serving one is dead.
	local parsed
	parsed="$(python3 - "$manifest_file" <<'PY' 2>/dev/null || true
import json, re, sys

with open(sys.argv[1], "r", encoding="utf-8") as fh:
    data = json.load(fh)

version = str(data.get("version", "")).strip()
download_url = str(data.get("download_url", "")).strip()

if not version:
    sys.exit("manifest has no 'version'")
if not download_url:
    sys.exit("manifest has no 'download_url'")
if not re.fullmatch(r"\d+(\.\d+){1,3}(-[0-9A-Za-z.]+)?", version):
    sys.exit(f"manifest version is not a version string: {version!r}")

print(version)
print(download_url)
PY
)"
	[[ -n "$parsed" ]] || fail "manifest is not valid JSON, or is missing version/download_url" || return 1

	local manifest_version download_url
	manifest_version="$(sed -n 1p <<<"$parsed")"
	download_url="$(sed -n 2p <<<"$parsed")"
	echo "  version:      $manifest_version"
	echo "  download_url: $download_url"

	if [[ -n "$EXPECT_VERSION" && "$manifest_version" != "$EXPECT_VERSION" ]]; then
		fail "manifest advertises $manifest_version, expected $EXPECT_VERSION" || return 1
	fi

	[[ "$download_url" == https://* ]] || fail "download_url is not HTTPS — every site will refuse it" || return 1

	local host trusted=0
	host="$(python3 -c 'import sys,urllib.parse; print((urllib.parse.urlparse(sys.argv[1]).hostname or "").lower())' "$download_url")"
	local candidate
	for candidate in "${TRUSTED_HOSTS[@]}"; do
		[[ "$host" == "$candidate" ]] && trusted=1
	done
	[[ "$trusted" -eq 1 ]] || fail "download_url host '$host' is not in the plugin's trusted-package allowlist (${TRUSTED_HOSTS[*]}) — installs will be blocked" || return 1

	local zip_status
	zip_status="$(curl -sS -L -o "$zip_file" -w '%{http_code}' "$download_url" 2>/dev/null || echo "000")"
	[[ "$zip_status" == "200" ]] || fail "download_url returned HTTP $zip_status" || return 1

	local zip_root
	zip_root="$(unzip -Z1 "$zip_file" 2>/dev/null | sed -n 1p | cut -d/ -f1)"
	[[ -n "$zip_root" ]] || fail "download_url did not serve a readable ZIP" || return 1

	# The updater is what installs every future release. A package that ships
	# without it strands the site on that version permanently.
	local updater_count
	updater_count="$(unzip -Z1 "$zip_file" 2>/dev/null | grep -c "^$zip_root/includes/class-mcpwp-updater.php$" || true)"
	[[ "$updater_count" -ge 1 ]] || fail "served package has no includes/class-mcpwp-updater.php — it would strand every site that installs it" || return 1

	# The check that #716's neighbourhood was missing: the manifest and the
	# bytes it points at must agree. When they don't, sites "update" and land
	# back on the version they started from, and are re-offered it forever.
	python3 "$SCRIPT_DIR/verify_release_compatibility.py" \
		--manifest "$manifest_file" --zip "$zip_file" || return 1
	echo "  served ZIP:   $manifest_version (header verified)"

	echo "  OK: channel can deliver $manifest_version"
	return 0
}

attempt=1
while true; do
	if verify_once; then
		exit 0
	fi

	if [[ "$attempt" -ge "$RETRIES" ]]; then
		echo "ERROR: update channel verification failed after $attempt attempt(s): $MANIFEST_URL" >&2
		exit 1
	fi

	echo "  retrying in ${DELAY}s (attempt $((attempt + 1))/$RETRIES) — CDN propagation is the usual cause" >&2
	sleep "$DELAY"
	attempt=$((attempt + 1))
done
