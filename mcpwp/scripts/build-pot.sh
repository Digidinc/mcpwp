#!/usr/bin/env bash
# Rebuild the shipped translation template deterministically from runtime source.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT="$PLUGIN_DIR/languages/mumega-mcp.pot"
TEMP="$PLUGIN_DIR/languages/.mumega-mcp.pot.$$"
WP_CLI_IMAGE="wordpress:cli@sha256:dac35b8f34c6f4f1fafa0b32342642fa9eecd077bb0a8909b486c108874bd7f1"
trap 'rm -f "$TEMP"' EXIT

docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
	-v "$PLUGIN_DIR:/app" -w /app "$WP_CLI_IMAGE" \
	wp i18n make-pot . "languages/$(basename "$TEMP")" \
	--slug=mumega-mcp --domain=mumega-mcp \
	--exclude=tests,vendor,scripts,assets --skip-js \
	--headers='{"Report-Msgid-Bugs-To":""}' \
	--file-comment='Copyright (C) 2026 Mumega'

python3 - "$TEMP" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
matches = 0
for index, line in enumerate(lines):
    if line.startswith('"POT-Creation-Date:'):
        lines[index] = '"POT-Creation-Date: 1980-01-01T00:00:00+00:00\\n"\n'
        matches += 1
if matches != 1:
    raise SystemExit(f"Expected one POT-Creation-Date header, found {matches}")
path.write_text("".join(lines), encoding="utf-8", newline="\n")
PY

mv "$TEMP" "$OUTPUT"
echo "[OK] Rebuilt $OUTPUT"
