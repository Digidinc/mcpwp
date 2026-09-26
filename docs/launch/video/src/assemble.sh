#!/usr/bin/env bash
# Assembles the 7 captured scene clips (docs/launch/video/src/out/sceneN.webm) into the
# final Product Hunt demo video: trims each to its scripted duration, normalizes to a
# common 1920x1080/30fps/yuv420p format, crossfades between scenes (0.5s), and produces
# the 1080p MP4 plus a 1270x760 cut. No audio track (captions are burned in).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$HERE/out"
FINAL_DIR="$(cd "$HERE/.." && pwd)"   # docs/launch/video/

# scene name -> exact duration (seconds), matching capture.js
declare -A DUR=( [scene1]=7 [scene2]=9 [scene3]=15 [scene4]=11 [scene5]=11 [scene6]=9 [scene7]=7 )
ORDER=(scene1 scene2 scene3 scene4 scene5 scene6 scene7)
FADE=0.5

echo "== Step 1: trim + normalize each scene =="
for s in "${ORDER[@]}"; do
  ffmpeg -y -i "$OUT/$s.webm" -t "${DUR[$s]}" \
    -vf "fps=30,format=yuv420p" \
    -c:v libx264 -crf 18 -preset veryfast \
    -an "$OUT/${s}_norm.mp4" -loglevel error
  echo "  $s -> ${DUR[$s]}s"
done

echo "== Step 2: crossfade chain =="
INPUTS=()
for s in "${ORDER[@]}"; do
  INPUTS+=(-i "$OUT/${s}_norm.mp4")
done

# Build cumulative xfade offsets: offset_i = cumulative_length_so_far - FADE
FILTER=""
cum="${DUR[${ORDER[0]}]}"
prev_label="0:v"
idx=1
for ((i=1; i<${#ORDER[@]}; i++)); do
  s="${ORDER[$i]}"
  d="${DUR[$s]}"
  offset=$(python3 -c "print(round($cum - $FADE, 3))")
  out_label="v$idx"
  FILTER+="[$prev_label][$i:v]xfade=transition=fade:duration=${FADE}:offset=${offset}[$out_label];"
  cum=$(python3 -c "print(round($cum + $d - $FADE, 3))")
  prev_label="$out_label"
  idx=$((idx+1))
done
# strip trailing semicolon, drop final label rename (use prev_label as map target)
FILTER="${FILTER%;}"

echo "  total final duration: ${cum}s"

ffmpeg -y "${INPUTS[@]}" -filter_complex "$FILTER" -map "[$prev_label]" \
  -c:v libx264 -crf 20 -preset medium -pix_fmt yuv420p -movflags +faststart \
  "$OUT/assembled_1080p.mp4" -loglevel error

echo "== Step 3: final 1080p export (size-capped) =="
cp "$OUT/assembled_1080p.mp4" "$FINAL_DIR/mcpwp-demo-1080p.mp4"
ls -la "$FINAL_DIR/mcpwp-demo-1080p.mp4"

echo "== Step 4: 1270x760 cut =="
ffmpeg -y -i "$FINAL_DIR/mcpwp-demo-1080p.mp4" \
  -vf "scale=1270:760:force_original_aspect_ratio=increase,crop=1270:760" \
  -c:v libx264 -crf 20 -preset medium -pix_fmt yuv420p -movflags +faststart \
  "$FINAL_DIR/mcpwp-demo-1270x760.mp4" -loglevel error
ls -la "$FINAL_DIR/mcpwp-demo-1270x760.mp4"

echo "== Step 5: poster frame =="
ffmpeg -y -ss 21 -i "$FINAL_DIR/mcpwp-demo-1080p.mp4" -frames:v 1 "$FINAL_DIR/poster.png" -loglevel error
ls -la "$FINAL_DIR/poster.png"

echo "done"
