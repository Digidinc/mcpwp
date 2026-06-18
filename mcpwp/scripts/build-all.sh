#!/usr/bin/env bash
#
# Build all three MCPWP distribution zips in one shot.
#
#   mcpwp-<version>.zip           — WordPress.org (free, no Pro)
#   mcpwp-agency-<version>.zip    — Agency (all Pro features on, no license key)
#   mcpwp-freemius-<version>.zip  — Freemius (Pro via license key)
#
# Usage:
#   bash scripts/build-all.sh
#   bash scripts/build-all.sh --version 3.2.7

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VERSION=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="$2"; shift 2 ;;
		*) echo "Unknown option: $1"; exit 1 ;;
	esac
done

VERSION_ARG=""
if [[ -n "$VERSION" ]]; then
	VERSION_ARG="--version $VERSION"
fi

echo "======================================"
echo " MCPWP — building all distributions"
echo "======================================"
echo ""

bash "$SCRIPT_DIR/build-wporg.sh"     $VERSION_ARG
bash "$SCRIPT_DIR/build-agency.sh"    $VERSION_ARG
bash "$SCRIPT_DIR/build-freemius.sh"  $VERSION_ARG

echo "======================================"
echo " All builds complete:"
ls -lh "$SCRIPT_DIR"/mcpwp-*.zip 2>/dev/null | awk '{print "  "$NF" ("$5")"}'
echo "======================================"
