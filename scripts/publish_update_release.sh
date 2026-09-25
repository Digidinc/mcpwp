#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/mcpwp"
PLUGIN_MAIN="$PLUGIN_DIR/mcpwp.php"
README_FILE="$PLUGIN_DIR/readme.txt"
MANIFEST_FILE="$ROOT_DIR/version.json"
BUILD_SCRIPT="$PLUGIN_DIR/scripts/build-selfhosted.sh"
AGENCY_BUILD_SCRIPT="$PLUGIN_DIR/scripts/build-agency.sh"

BUILD=0
VERIFY_ONLY=0
ALLOW_REPUBLISH=0

usage() {
	cat <<'EOF'
Usage:
  scripts/publish_update_release.sh [options]

Options:
  --build            Build the ZIPs before publishing
  --verify-only      Run consistency and live artifact checks without publishing
  --allow-republish  Publish even if the channel already serves this version or
                     a newer one. Default: refuse, because re-publishing an
                     equal-or-older version is a no-op for sites already on it.
  --r2-only          Deprecated no-op, accepted for backward compatibility.
                     Uploading to the R2 bucket is now the only publish mode;
                     there is no longer a VPS static copy to skip.
  -h, --help         Show help

Notes:
  - Publishing uploads version.json + the self-hosted ZIP + the agency ZIP to
    the R2 bucket mumcp-updates. Both public channels — updates.mcpwp.net and
    mumega.com/mcp-updates/* — are Cloudflare Workers backed by that one bucket,
    so a single upload updates all of them. No VPS filesystem access is involved.
  - The canonical manifest is https://updates.mcpwp.net/version.json
  - The self-hosted package bundles the updater so sites can self-update
EOF
}

require_cmd() {
	command -v "$1" >/dev/null 2>&1 || {
		echo "Missing required command: $1" >&2
		exit 1
	}
}

while [[ $# -gt 0 ]]; do
	case "$1" in
	--build)
		BUILD=1
		shift
		;;
	--verify-only)
		VERIFY_ONLY=1
		shift
		;;
	--allow-republish)
		ALLOW_REPUBLISH=1
		shift
		;;
	--r2-only)
		# Deprecated no-op: R2 is now the only publish target — there is no VPS
		# static copy left to skip. Accepted so existing callers do not break.
		shift
		;;
	-h | --help)
		usage
		exit 0
		;;
	*)
		echo "Unknown argument: $1" >&2
		usage
		exit 1
		;;
	esac
done

require_cmd python3
require_cmd curl
require_cmd unzip

PLUGIN_VERSION="$(grep -m1 "Version:" "$PLUGIN_MAIN" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
DEFINE_VERSION="$(grep -E "^define\( 'MCPWP_VERSION'" "$PLUGIN_MAIN" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
README_VERSION="$(grep -m1 '^Stable tag:' "$README_FILE" | sed 's/Stable tag:[[:space:]]*//')"
MANIFEST_VERSION="$(python3 - "$MANIFEST_FILE" <<'PY'
import json, sys
with open(sys.argv[1], 'r', encoding='utf-8') as fh:
    data = json.load(fh)
print(data["version"])
PY
)"

if [[ "$PLUGIN_VERSION" != "$DEFINE_VERSION" || "$PLUGIN_VERSION" != "$README_VERSION" || "$PLUGIN_VERSION" != "$MANIFEST_VERSION" ]]; then
	echo "Version mismatch detected:" >&2
	echo "  plugin header: $PLUGIN_VERSION" >&2
	echo "  MCPWP_VERSION:  $DEFINE_VERSION" >&2
	echo "  readme tag:    $README_VERSION" >&2
	echo "  version.json:  $MANIFEST_VERSION" >&2
	exit 1
fi

VERSION="$PLUGIN_VERSION"

# WordPress uses the manifest's requirements before downloading the ZIP. A
# correct version number cannot compensate for an incompatible minimum.
python3 "$ROOT_DIR/scripts/verify_release_compatibility.py" \
	--manifest "$MANIFEST_FILE" --plugin "$PLUGIN_MAIN" --readme "$README_FILE"

ZIP_PATH="$PLUGIN_DIR/scripts/mcpwp-selfhosted-$VERSION.zip"
AGENCY_ZIP="$PLUGIN_DIR/scripts/mcpwp-agency-$VERSION.zip"

if [[ "$BUILD" -eq 1 ]]; then
	bash "$BUILD_SCRIPT" --version "$VERSION"
	# The agency build (Pro unlocked via a build-only marker file,
	# includes/agency-license.php — not a wp-config.php constant, see #863) is
	# a separate, token-gated distribution stored under the agency/ prefix of
	# the same bucket (#852). Build it here so it exists for every release
	# instead of relying on a remembered manual wrangler upload.
	bash "$AGENCY_BUILD_SCRIPT" --version "$VERSION"
fi

if [[ ! -f "$ZIP_PATH" ]]; then
	echo "Missing ZIP artifact: $ZIP_PATH" >&2
	echo "Run with --build or build it first." >&2
	exit 1
fi

# Fail before any upload if a publish is asked for without the agency ZIP —
# a partial publish (canonical up, agency missing) would leave agencies without
# the new version.
if [[ "$VERIFY_ONLY" -eq 0 && ! -f "$AGENCY_ZIP" ]]; then
	echo "Missing agency ZIP artifact: $AGENCY_ZIP" >&2
	echo "Run with --build or build it first." >&2
	exit 1
fi

# The filenames are not version evidence: inspect both artifacts before upload.
python3 "$ROOT_DIR/scripts/verify_release_compatibility.py" \
	--manifest "$MANIFEST_FILE" --zip "$ZIP_PATH"

if [[ "$VERIFY_ONLY" -eq 0 ]]; then
	python3 "$ROOT_DIR/scripts/verify_release_compatibility.py" \
		--manifest "$MANIFEST_FILE" --zip "$AGENCY_ZIP"
fi

# grep -q closes the pipe early, causing SIGPIPE (141) under pipefail — use grep -c instead.
UPDATER_COUNT=$(unzip -Z1 "$ZIP_PATH" | grep -c 'includes/class-mcpwp-updater.php' || true)
if [[ "$UPDATER_COUNT" -eq 0 ]]; then
	echo "Updater missing from canonical self-hosted ZIP: $ZIP_PATH" >&2
	exit 1
fi

if [[ "$VERIFY_ONLY" -eq 0 ]]; then
	# Refuse to publish a version the channel already serves (or a downgrade).
	# The updater compares version strings, so re-publishing an equal-or-older
	# version is a silent no-op for every site already on it — this is exactly
	# how publishing 3.7.0 over an existing 3.7.0 once stranded two security
	# fixes. Fail-open: if the live version can't be read (channel/CDN down),
	# warn and proceed — a channel outage must never block a real release, and
	# the post-publish verify below is the backstop. --allow-republish overrides.
	if [[ "$ALLOW_REPUBLISH" -eq 0 ]]; then
		LIVE_JSON="$(curl -fsSL "https://updates.mcpwp.net/version.json" 2>/dev/null || true)"
		GUARD="$(VERSION="$VERSION" python3 - "$LIVE_JSON" <<'PY'
import json, os, sys

raw = sys.argv[1] if len(sys.argv) > 1 else ""
target = os.environ["VERSION"]
try:
    live = str(json.loads(raw)["version"]).strip()
except Exception:
    print("unknown")
    sys.exit(0)


def parse(v):
    return [int(x) for x in v.split(".") if x.isdigit()]


a, b = parse(live), parse(target)
n = max(len(a), len(b))
a += [0] * (n - len(a))
b += [0] * (n - len(b))
print(f"block {live}" if a >= b else f"ok {live}")
PY
)"
		case "$GUARD" in
		unknown)
			echo "  WARNING: could not read the live channel version — publishing without the already-published guard." >&2
			;;
		block\ *)
			LIVE_VERSION="${GUARD#block }"
			echo "ERROR: the channel already serves $LIVE_VERSION; refusing to publish $VERSION (equal or older)." >&2
			echo "       Sites already on $LIVE_VERSION would see this as a no-op update." >&2
			echo "       If this re-publish is intentional (e.g. fixing a broken upload), pass --allow-republish." >&2
			exit 1
			;;
		esac
	fi

	# Upload to Cloudflare R2. Both public channels (updates.mcpwp.net and
	# mumega.com/mcp-updates/*) are Cloudflare Workers backed by this one bucket,
	# so a single upload updates every channel — no VPS filesystem write.
	# --remote targets production R2; without it wrangler writes to local miniflare.
	# MCPWP_WORKER_DIR overrides the default sibling-checkout assumption — needed
	# in CI, where mcpwp-updates-worker isn't checked out next to this repo.
	WORKER_DIR="${MCPWP_WORKER_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/mcpwp-updates-worker}"
	if [[ ! -d "$WORKER_DIR" ]]; then
		echo "ERROR: worker dir not found ($WORKER_DIR) — R2 not updated, live manifest stays stale" >&2
		exit 1
	fi
	if ! command -v npx >/dev/null 2>&1; then
		echo "ERROR: npx not found — R2 not updated, live manifest stays stale" >&2
		exit 1
	fi
	CF_TOKEN="${CLOUDFLARE_API_TOKEN:-$(grep CLOUDFLARE_API_TOKEN ~/.env.secrets 2>/dev/null | cut -d= -f2)}"
	if [[ -z "$CF_TOKEN" ]]; then
		echo "ERROR: CLOUDFLARE_API_TOKEN not set — R2 not updated, live manifest stays stale" >&2
		exit 1
	fi
	CLOUDFLARE_API_TOKEN="$CF_TOKEN" npx --prefix "$WORKER_DIR" wrangler r2 object put mumcp-updates/version.json \
		--file="$MANIFEST_FILE" --content-type="application/json" --remote
	# The manifest's download_url is mcpwp-latest.zip — this key MUST exist in R2 or downloads 404.
	CLOUDFLARE_API_TOKEN="$CF_TOKEN" npx --prefix "$WORKER_DIR" wrangler r2 object put mumcp-updates/mcpwp-latest.zip \
		--file="$ZIP_PATH" --content-type="application/zip" --remote
	# Versioned agency package (Pro unlocked via a build-only marker file, not
	# a wp-config.php constant — #863), never in the public bucket (#852): the
	# legacy spai-updates Worker reads mumcp-updates without auth.
	# It goes to the private mcpwp-agency-builds bucket, which only the
	# mcpwp-updates Worker can read, and only for a request carrying a download
	# token. If that bucket does not exist this upload fails, and so does the
	# release: loud, never public.
	CLOUDFLARE_API_TOKEN="$CF_TOKEN" npx --prefix "$WORKER_DIR" wrangler r2 object put "mcpwp-agency-builds/mcpwp-agency-$VERSION.zip" \
		--file="$AGENCY_ZIP" --content-type="application/zip" --remote
	echo "  R2 upload: version.json + mcpwp-latest.zip → mumcp-updates; mcpwp-agency-$VERSION.zip → mcpwp-agency-builds (private)"
fi

# Prove the channel can actually deliver what we just published, and FAIL the
# publish if it cannot. These checks used to print "Note: ..." and exit 0, so a
# half-published release — manifest bumped, ZIP missing or stale — reported
# success and nobody found out until a site asked for an update and got
# nothing. Both hosts are served from the same R2 bucket by CF Workers, so a
# successful upload should be visible on either; the retries absorb CDN
# propagation, not a failed upload.
#
# Every published channel is verified, not just the one this run wrote to: if
# they have drifted apart, the release is not shipped and we want to know now.
VERIFY_SCRIPT="$ROOT_DIR/scripts/verify_update_channel.sh"
VERIFY_FAILED=0
for CHANNEL_URL in \
	"https://updates.mcpwp.net/version.json" \
	"https://mumega.com/mcp-updates/version.json"; do
	if ! bash "$VERIFY_SCRIPT" \
		--manifest-url "$CHANNEL_URL" \
		--expect-version "$VERSION" \
		--retries 4 \
		--delay 20; then
		VERIFY_FAILED=1
	fi
done

# An agency build must never be downloadable without a token (#852). Two ways it
# could be: a Worker serving it (checked by requesting it without a token), or
# the private bucket itself being exposed (public r2.dev URL, custom domain) —
# which no Worker request can see, so the bucket's own settings are checked.
# Only an explicit refusal passes: 401 or 404. Anything else — 200, a redirect,
# a challenge page, a 5xx, no response — is retried and then fails the release,
# because "could not tell" must never read as "not public".
AGENCY_LEAK=0
for LEAK_URL in \
	"https://updates.mcpwp.net/agency/mcpwp-agency-$VERSION.zip" \
	"https://updates.mcpwp.net/mcpwp-agency-$VERSION.zip" \
	"https://mumega.com/mcp-updates/agency/mcpwp-agency-$VERSION.zip" \
	"https://mumega.com/mcp-updates/mcpwp-agency-$VERSION.zip"; do
	LEAK_CODE=""
	for LEAK_TRY in 1 2 3; do
		# HEAD, no redirects followed, one status line only: curl prints 000 itself
		# on no response, so nothing is appended to the code.
		LEAK_CODE="$(curl -sS -I -o /dev/null -w '%{http_code}' --max-time 30 "$LEAK_URL" 2>/dev/null)"
		[[ "$LEAK_CODE" == "401" || "$LEAK_CODE" == "404" ]] && break
		sleep 5
	done
	if [[ "$LEAK_CODE" != "401" && "$LEAK_CODE" != "404" ]]; then
		echo "ERROR: agency build check did not get an explicit refusal (HTTP ${LEAK_CODE:-none}): $LEAK_URL" >&2
		AGENCY_LEAK=1
	fi
done

if [[ "$VERIFY_ONLY" -eq 0 ]]; then
	# The private bucket must have no public r2.dev URL and no custom domain.
	AGENCY_DEV_URL="$(CLOUDFLARE_API_TOKEN="$CF_TOKEN" npx --prefix "$WORKER_DIR" wrangler r2 bucket dev-url get mcpwp-agency-builds 2>&1 || true)"
	if ! printf '%s' "$AGENCY_DEV_URL" | grep -q "Public access via the r2.dev URL is disabled"; then
		echo "ERROR: could not confirm the r2.dev URL of mcpwp-agency-builds is disabled:" >&2
		printf '%s\n' "$AGENCY_DEV_URL" | tail -3 >&2
		AGENCY_LEAK=1
	fi
	AGENCY_DOMAINS="$(CLOUDFLARE_API_TOKEN="$CF_TOKEN" npx --prefix "$WORKER_DIR" wrangler r2 bucket domain list mcpwp-agency-builds 2>&1 || true)"
	# Pass only on wrangler's explicit "none" answer; any other output (a listed
	# domain, an auth error, a changed message) fails closed.
	if ! printf '%s' "$AGENCY_DOMAINS" | grep -q "There are no custom domains connected to this bucket"; then
		echo "ERROR: could not confirm mcpwp-agency-builds has no custom domain:" >&2
		printf '%s\n' "$AGENCY_DOMAINS" | tail -5 >&2
		AGENCY_LEAK=1
	fi
fi

if [[ "$AGENCY_LEAK" -eq 1 ]]; then
	echo "" >&2
	echo "ERROR: MCPWP $VERSION is NOT shipped — an agency build is (or may be) public." >&2
	exit 1
fi

if [[ "$VERIFY_FAILED" -eq 1 ]]; then
	echo "" >&2
	echo "ERROR: MCPWP $VERSION is NOT shipped — at least one channel cannot deliver it." >&2
	echo "       The artifacts above are what live sites will actually receive." >&2
	exit 1
fi

echo "Published MCPWP $VERSION"
echo "  canonical manifest: https://updates.mcpwp.net/version.json"
echo "  canonical zip:      https://updates.mcpwp.net/mcpwp-latest.zip"
echo "  agency zip:         https://updates.mcpwp.net/agency/mcpwp-agency-$VERSION.zip (Authorization: Bearer <agency token> required)"
echo "  mirror (same R2):   https://mumega.com/mcp-updates/version.json"
