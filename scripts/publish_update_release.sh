#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/mcpwp"
PLUGIN_MAIN="$PLUGIN_DIR/mcpwp.php"
README_FILE="$PLUGIN_DIR/readme.txt"
MANIFEST_FILE="$ROOT_DIR/version.json"
BUILD_SCRIPT="$PLUGIN_DIR/scripts/build-selfhosted.sh"
STATIC_DIR="/var/www/mcp-updates"
STATIC_ZIP="$STATIC_DIR/mcpwp-latest.zip"
STATIC_MANIFEST="$STATIC_DIR/version.json"

BUILD=0
VERIFY_ONLY=0
R2_ONLY=0

usage() {
	cat <<'EOF'
Usage:
  scripts/publish_update_release.sh [options]

Options:
  --build          Build the ZIP before publishing
  --verify-only    Run consistency and live artifact checks without publishing
  --r2-only        Publish to the mumcp-updates R2 bucket only — skip the
                    /var/www/mcp-updates static copy (requires VPS filesystem
                    access this flag doesn't have, e.g. from GitHub Actions)
  -h, --help       Show help

Notes:
  - The canonical updater source is https://mumega.com/mcp-updates/version.json
  - This channel serves the self-hosted package with the legacy updater included
  - The worker is optional and no longer part of the critical path
  - --r2-only updates updates.mcpwp.net immediately; mumega.com/mcp-updates/*
    (legacy, un-migrated 2.8.56 sites) still needs a manual publish from a host
    with /var/www/mcp-updates access until that channel is retired
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
	--r2-only)
		R2_ONLY=1
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
ZIP_PATH="$PLUGIN_DIR/scripts/mcpwp-selfhosted-$VERSION.zip"

if [[ "$BUILD" -eq 1 ]]; then
	bash "$BUILD_SCRIPT" --version "$VERSION"
fi

if [[ ! -f "$ZIP_PATH" ]]; then
	echo "Missing ZIP artifact: $ZIP_PATH" >&2
	echo "Run with --build or build it first." >&2
	exit 1
fi

ZIP_ROOT="$(unzip -Z1 "$ZIP_PATH" | sed -n '1p' | cut -d/ -f1)"
ZIP_VERSION="$(unzip -p "$ZIP_PATH" "$ZIP_ROOT/mcpwp.php" | grep -m1 'Version:' | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [[ "$ZIP_VERSION" != "$VERSION" ]]; then
	echo "ZIP version mismatch: expected $VERSION, got $ZIP_VERSION" >&2
	exit 1
fi

# grep -q closes the pipe early, causing SIGPIPE (141) under pipefail — use grep -c instead.
UPDATER_COUNT=$(unzip -Z1 "$ZIP_PATH" | grep -c 'includes/class-mcpwp-updater.php' || true)
if [[ "$UPDATER_COUNT" -eq 0 ]]; then
	echo "Updater missing from canonical self-hosted ZIP: $ZIP_PATH" >&2
	exit 1
fi

if [[ "$VERIFY_ONLY" -eq 0 ]]; then
	if [[ "$R2_ONLY" -eq 0 ]]; then
		# Copy to a versioned file and point mcpwp-latest.zip at it (resolves #339).
		# Never cp to $STATIC_ZIP then ln -sf the same path — that produces a
		# self-referencing symlink and breaks every later publish.
		STATIC_VERSIONED_ZIP="$STATIC_DIR/mcpwp-$VERSION.zip"
		sudo -n cp "$ZIP_PATH" "$STATIC_VERSIONED_ZIP"
		sudo -n cp "$MANIFEST_FILE" "$STATIC_MANIFEST"
		sudo -n ln -sfn "$STATIC_VERSIONED_ZIP" "$STATIC_ZIP"
	fi

	# Upload to Cloudflare R2 — CF Worker (spai-updates) serves mumega.com/mcp-updates/*
	# from the mumcp-updates bucket, not from the nginx alias.
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
	echo "  R2 upload: version.json + mcpwp-latest.zip → mumcp-updates bucket"
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

if [[ "$VERIFY_FAILED" -eq 1 ]]; then
	echo "" >&2
	echo "ERROR: MCPWP $VERSION is NOT shipped — at least one channel cannot deliver it." >&2
	echo "       The artifacts above are what live sites will actually receive." >&2
	exit 1
fi

echo "Published MCPWP $VERSION"
if [[ "$R2_ONLY" -eq 1 ]]; then
	echo "  canonical manifest: https://updates.mcpwp.net/version.json"
	echo "  canonical zip:      https://updates.mcpwp.net/mcpwp-latest.zip"
	echo "  NOTE: legacy mumega.com/mcp-updates/* was NOT touched (--r2-only) —"
	echo "        still needs a manual publish from a host with VPS filesystem access"
else
	echo "  static manifest: https://mumega.com/mcp-updates/version.json"
	echo "  static zip:      https://mumega.com/mcp-updates/mcpwp-latest.zip"
fi
