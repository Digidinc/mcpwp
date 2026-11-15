#!/bin/bash
# Build the MCPWP .mcpb Desktop Extension
# Usage: bash build-mcpb.sh
# Output: mcpwp-3.0.0.mcpb

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION=$(node -p "require('./package.json').version")
OUTPUT="mcpwp-${VERSION}.mcpb"

echo "Building MCPWP Desktop Extension v${VERSION}..."

# Build the JS bundle first
bun run build

# Create a staging directory
STAGING=$(mktemp -d)
trap "rm -rf $STAGING" EXIT

# Copy required files
cp manifest.json "$STAGING/"
cp package.json "$STAGING/"
cp README.md "$STAGING/"
cp -r dist/ "$STAGING/dist/"

# Create the .mcpb zip
cd "$STAGING"
zip -r "${SCRIPT_DIR}/${OUTPUT}" . -x "*.DS_Store"
cd "$SCRIPT_DIR"

echo "✅ Built: ${OUTPUT}"
echo ""
echo "To install locally: open ${OUTPUT} in Claude Desktop"
echo "To submit: https://clau.de/desktop-extention-submission"
