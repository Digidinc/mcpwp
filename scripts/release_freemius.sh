#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

usage() {
	cat <<'EOF'
Usage:
  scripts/release_freemius.sh --version X.Y.Z [options]

Required:
  --version X.Y.Z                Release version for the plugin

Options:
  --token TOKEN                  Freemius bearer token (or FREEMIUS_BEARER_TOKEN)
  --product-id ID                Freemius product id (default: 23824 or FREEMIUS_PRODUCT_ID)
  --release-mode MODE            release mode for tag update (default: released).
                                Allowed: released | pending | beta | all.
                                Alias: unreleased -> pending.
  --upload-premium-zip           Also upload a premium zip (legacy Freemius setup).
                                Default is single-plugin distribution (no premium zip).
  --skip-bump                    Do not edit plugin/readme/changelog versions
  --dry-run                      Build and print actions; skip Freemius API calls
  --keep-zips                    Keep generated zip files in repo root
  -h, --help                     Show help

Examples:
  FREEMIUS_BEARER_TOKEN=... scripts/release_freemius.sh --version 1.0.23
  scripts/release_freemius.sh --version 1.0.23 --token ... --dry-run
EOF
}

require_cmd() {
	command -v "$1" >/dev/null 2>&1 || {
		echo "Missing required command: $1" >&2
		exit 1
	}
}

replace_or_fail() {
	local file="$1"
	local pattern="$2"
	local replacement="$3"
	if ! grep -Eq "$pattern" "$file"; then
		echo "Pattern not found in $file: $pattern" >&2
		exit 1
	fi
	sed -E -i "s|$pattern|$replacement|" "$file"
}

VERSION=""
TOKEN="${FREEMIUS_BEARER_TOKEN:-}"
PRODUCT_ID="${FREEMIUS_PRODUCT_ID:-23824}"
RELEASE_MODE="released"
UPLOAD_PREMIUM_ZIP=0
SKIP_BUMP=0
DRY_RUN=0
KEEP_ZIPS=0

while [[ $# -gt 0 ]]; do
	case "$1" in
	--version)
		VERSION="${2:-}"
		shift 2
		;;
	--token)
		TOKEN="${2:-}"
		shift 2
		;;
	--product-id)
		PRODUCT_ID="${2:-}"
		shift 2
		;;
	--release-mode)
		RELEASE_MODE="${2:-}"
		shift 2
		;;
	--upload-premium-zip)
		UPLOAD_PREMIUM_ZIP=1
		shift
		;;
	--skip-bump)
		SKIP_BUMP=1
		shift
		;;
	--dry-run)
		DRY_RUN=1
		shift
		;;
	--keep-zips)
		KEEP_ZIPS=1
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

if [[ -z "$VERSION" ]]; then
	echo "--version is required" >&2
	usage
	exit 1
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid version: $VERSION (expected X.Y.Z)" >&2
	exit 1
fi

if [[ "$RELEASE_MODE" == "unreleased" ]]; then
	RELEASE_MODE="pending"
fi

if [[ ! "$RELEASE_MODE" =~ ^(released|pending|beta|all)$ ]]; then
	echo "Invalid --release-mode: $RELEASE_MODE (expected released|pending|beta|all)" >&2
	exit 1
fi

require_cmd zip
require_cmd curl
require_cmd sed
require_cmd grep
require_cmd python3

if [[ "$DRY_RUN" -eq 0 && -z "$TOKEN" ]]; then
	echo "Freemius bearer token is required (--token or FREEMIUS_BEARER_TOKEN)" >&2
	exit 1
fi

FREE_MAIN_FILE="mcpwp/mcpwp.php"
FREE_README_FILE="mcpwp/readme.txt"
FREE_CHANGELOG_FILE="mcpwp/CHANGELOG.md"
FREE_FS_INIT_FILE="mcpwp/includes/freemius-init.php"
FREE_LICENSE_FILE="mcpwp/includes/class-mcpwp-license.php"

if [[ "$SKIP_BUMP" -eq 0 ]]; then
	echo "Bumping plugin versions to $VERSION"

	replace_or_fail "$FREE_MAIN_FILE" "^ \\* Version:[[:space:]]+.*$" " * Version:           $VERSION"
	replace_or_fail "$FREE_MAIN_FILE" "^define\( 'MCPWP_VERSION', '[^']+' \);$" "define( 'MCPWP_VERSION', '$VERSION' );"
	replace_or_fail "$FREE_README_FILE" "^Stable tag: .*$" "Stable tag: $VERSION"

	if ! grep -q "^## \[$VERSION\]" "$FREE_CHANGELOG_FILE"; then
		DATE_TODAY="$(date +%Y-%m-%d)"
		sed -i "0,/^## \[/s//## [$VERSION] - $DATE_TODAY\\n\\n### Changed\\n- Release automation update.\\n\\n## [/" "$FREE_CHANGELOG_FILE"
	fi
fi

FREE_ZIP="mcpwp-$VERSION.zip"
PREMIUM_ZIP="mcpwp-premium-$VERSION.zip"

echo "Building zip package (mcpwp/scripts/build-freemius.sh)"
# One Freemius packager, not two (#497). This script used to rsync its own
# package, which drifted from build-freemius.sh: it kept uninstall.php, which
# build-freemius.sh strips (#943 BLOCK-1c) and which the Freemius API rejects
# outright ("uninstall_script", HTTP 400). Build with the real Freemius build
# script and upload exactly what it produced and verified.
bash "$ROOT_DIR/mcpwp/scripts/build-freemius.sh" --version "$VERSION"
BUILT_ZIP="$ROOT_DIR/mcpwp/scripts/mcpwp-freemius-$VERSION.zip"
if [[ ! -f "$BUILT_ZIP" ]]; then
	echo "build-freemius.sh did not produce $BUILT_ZIP" >&2
	exit 1
fi
if unzip -Z1 "$BUILT_ZIP" | grep -qx 'mcpwp/uninstall.php'; then
	echo "Refusing to upload: $BUILT_ZIP contains mcpwp/uninstall.php (Freemius rejects it)" >&2
	exit 1
fi
if ! unzip -p "$BUILT_ZIP" mcpwp/mcpwp.php | grep -q "define( 'MCPWP_FREEMIUS_BUILD', true );"; then
	echo "Refusing to upload: $BUILT_ZIP lacks the MCPWP_FREEMIUS_BUILD constant" >&2
	exit 1
fi
mv "$BUILT_ZIP" "$ROOT_DIR/$FREE_ZIP"

if [[ "$UPLOAD_PREMIUM_ZIP" -eq 1 ]]; then
	echo "Building premium zip package"
	(
		BUILD_DIR="$(mktemp -d)"
		cp -R "mcpwp" "$BUILD_DIR/mcpwp-premium"
		cd "$BUILD_DIR"
		zip -qr "$ROOT_DIR/$PREMIUM_ZIP" "mcpwp-premium"
		rm -rf "$BUILD_DIR" || true
	)
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
	echo "Dry run complete."
	echo "- Free zip: $FREE_ZIP"
	if [[ "$UPLOAD_PREMIUM_ZIP" -eq 1 ]]; then
		echo "- Premium zip: $PREMIUM_ZIP"
	fi
	echo "- API calls skipped"
	exit 0
fi

echo "Uploading $VERSION to Freemius product $PRODUCT_ID"
CREATE_RESPONSE="$(
	curl -sS -X POST "https://api.freemius.com/v1/products/$PRODUCT_ID/tags.json" \
		-H "Authorization: Bearer $TOKEN" \
		-F "file=@$FREE_ZIP;type=application/zip" \
		-F "add_contributor_to_rel=false"
)"

TAG_ID="$(printf '%s' "$CREATE_RESPONSE" | python3 -c 'import json,sys
try:
    data=json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
print(data.get("id",""))')"

if [[ -z "$TAG_ID" ]]; then
	echo "Freemius upload failed:"
	printf '%s\n' "$CREATE_RESPONSE"
	echo "Tip: If error is duplicate_plugin_version, bump --version and retry."
	exit 1
fi

echo "Uploaded tag id: $TAG_ID"

if [[ "$UPLOAD_PREMIUM_ZIP" -eq 1 ]]; then
	echo "Uploading premium package for tag $TAG_ID"
	PREMIUM_RESPONSE="$(
		curl -sS -X PUT "https://api.freemius.com/v1/products/$PRODUCT_ID/tags/$TAG_ID.json" \
			-H "Authorization: Bearer $TOKEN" \
			-F "file=@$PREMIUM_ZIP;type=application/zip" \
			-F "is_premium=true"
	)"

	# Best-effort sanity check.
	PREMIUM_OK="$(printf '%s' "$PREMIUM_RESPONSE" | python3 -c 'import json,sys
try:
    data=json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
print(data.get("version",""))')"
	if [[ "$PREMIUM_OK" != "$VERSION" ]]; then
		echo "Premium upload response:"
		printf '%s\n' "$PREMIUM_RESPONSE"
		echo "Premium upload failed or returned unexpected version" >&2
		exit 1
	fi
else
	echo "Skipping premium zip upload (single-plugin distribution)"
fi

echo "Setting release_mode=$RELEASE_MODE"
RELEASE_RESPONSE="$(
	curl -sS -X PUT "https://api.freemius.com/v1/products/$PRODUCT_ID/tags/$TAG_ID.json" \
		-H "Authorization: Bearer $TOKEN" \
		-H "Content-Type: application/x-www-form-urlencoded" \
		--data "release_mode=$RELEASE_MODE"
)"

FINAL_MODE="$(printf '%s' "$RELEASE_RESPONSE" | python3 -c 'import json,sys
try:
    data=json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
print(data.get("release_mode",""))')"

if [[ "$FINAL_MODE" != "$RELEASE_MODE" ]]; then
	echo "Release mode update response:"
	printf '%s\n' "$RELEASE_RESPONSE"
	echo "Expected release_mode=$RELEASE_MODE but got '$FINAL_MODE'" >&2
	exit 1
fi

echo "Freemius release successful"
echo "- product_id:   $PRODUCT_ID"
echo "- version:      $VERSION"
echo "- tag_id:       $TAG_ID"
echo "- release_mode: $FINAL_MODE"

if [[ "$KEEP_ZIPS" -eq 0 ]]; then
	rm -f "$FREE_ZIP"
	if [[ "$UPLOAD_PREMIUM_ZIP" -eq 1 ]]; then
		rm -f "$PREMIUM_ZIP"
	fi
	echo "Cleaned local zip artifacts"
else
	echo "Kept local zip artifacts"
fi
