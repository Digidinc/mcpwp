#!/usr/bin/env bash
# On-demand setup for the video-production tools that make the site video-first.
# Clones and prepares the tools OUTSIDE the repo (in $HOME/video-tools) so they
# are never vendored into git. Idempotent.
#
#   bash .cursor/video-tools-up.sh openmontage   # agentic video production (zero-key demo render)
#   bash .cursor/video-tools-up.sh openshorts    # AI long-video -> 9:16 shorts (Docker stack)
#   bash .cursor/video-tools-up.sh all           # both (default)
#
# OpenMontage: https://github.com/calesthio/OpenMontage  (Python + Remotion + FFmpeg)
# OpenShorts:  https://github.com/mutonby/openshorts     (Docker: FastAPI + Vite + Remotion)
set -euo pipefail

TOOLS_DIR="${VIDEO_TOOLS_DIR:-$HOME/video-tools}"
TARGET="${1:-all}"
mkdir -p "$TOOLS_DIR"

setup_openmontage() {
  echo "==> OpenMontage"
  cd "$TOOLS_DIR"
  [ -d OpenMontage/.git ] || git clone --depth 1 https://github.com/calesthio/OpenMontage.git
  cd OpenMontage
  command -v ffmpeg >/dev/null 2>&1 || { echo "ffmpeg missing — run .cursor/install.sh"; return 1; }
  make setup
  echo "==> Rendering a zero-key demo video to prove the pipeline works"
  .venv/bin/python render_demo.py focusflow-pitch
  echo "    Demo output: $TOOLS_DIR/OpenMontage/projects/demos/renders/focusflow-pitch.mp4"
  echo "    Render your own:  cd $TOOLS_DIR/OpenMontage && .venv/bin/python render_demo.py --list"
  echo "    Agentic mode: open this folder in an AI coding assistant (add keys to .env for cloud providers)."
}

setup_openshorts() {
  echo "==> OpenShorts"
  command -v docker >/dev/null 2>&1 || { echo "docker missing — run .cursor/install.sh"; return 1; }
  sudo docker info >/dev/null 2>&1 || { echo "dockerd not running — run .cursor/start.sh"; return 1; }
  cd "$TOOLS_DIR"
  [ -d openshorts/.git ] || git clone --depth 1 https://github.com/mutonby/openshorts.git
  cd openshorts
  # The image runs as baked user appuser (uid 999), but compose bind-mounts the
  # host source over /app, so appuser can't create its write dirs. Pre-create and
  # hand them to uid 999 before boot.
  mkdir -p uploads output output/thumbnails
  sudo chown -R 999:999 uploads output
  echo "    Building + starting the stack (first build pulls torch/whisper/yolo — several minutes)"
  sudo docker compose up --build -d
  echo -n "    Waiting for backend /health "
  for i in $(seq 1 60); do
    if curl -fsS http://127.0.0.1:8000/health >/dev/null 2>&1; then echo "OK"; break; fi
    echo -n "."; sleep 5
  done
  echo ""
  echo "============================================"
  echo "  OpenShorts stack up"
  echo "  Dashboard: http://127.0.0.1:5175"
  echo "  Backend:   http://127.0.0.1:8000/health"
  echo "  Renderer:  http://127.0.0.1:3100"
  echo "  NOTE: paste a Google Gemini API key in the dashboard on first use"
  echo "        (required for AI moment detection; stored in your browser only)."
  echo "============================================"
}

case "$TARGET" in
  openmontage) setup_openmontage ;;
  openshorts)  setup_openshorts ;;
  all)         setup_openmontage; setup_openshorts ;;
  *) echo "usage: $0 [openmontage|openshorts|all]"; exit 2 ;;
esac
