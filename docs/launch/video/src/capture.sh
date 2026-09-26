#!/usr/bin/env bash
# Records docs/launch/video/src/slides/scene*.html into docs/launch/video/src/out/sceneN.webm
# using Playwright + Chromium inside the official mcr.microsoft.com/playwright image.
#
# Each scene HTML is a self-contained 1920x1080 page with CSS animations timed to its
# own duration (see capture.js). This is how both the JSON-RPC "code panel" slides and
# the full-bleed real-screenshot slides get captured as actual rendered video frames
# rather than a static image conversion.
#
# Usage: bash capture.sh   (run from anywhere; paths are resolved relative to this file)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$HERE/out"

docker rm -f ph-vid-render >/dev/null 2>&1 || true

docker run -d --name ph-vid-render \
  -v "$HERE:/work/src" \
  -v "$HERE/out:/work/out" \
  mcr.microsoft.com/playwright:v1.48.0-jammy sleep infinity >/dev/null

# playwright browsers are already bundled in the image at /ms-playwright; installing the
# npm package (not bundled) reuses that cache instead of re-downloading Chromium.
docker exec ph-vid-render bash -lc \
  "mkdir -p /opt/pw && cd /opt/pw && npm init -y >/dev/null 2>&1 && \
   PLAYWRIGHT_BROWSERS_PATH=/ms-playwright npm install playwright@1.48.0 --no-audit --no-fund >/dev/null 2>&1"

docker exec ph-vid-render bash -lc \
  "cd /opt/pw && NODE_PATH=/opt/pw/node_modules node /work/src/capture.js"

docker rm -f ph-vid-render >/dev/null 2>&1

echo "captured scenes -> $HERE/out/*.webm"
echo "next: bash assemble.sh"
