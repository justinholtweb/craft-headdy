#!/usr/bin/env bash
# Renders the Plugin Store promo images from slides.html.
# Output: promos/out/headdy-promo-N.jpg at 1920x1080 (rendered at 2x, downsampled).
# Promos ship as JPEG, never PNG: a 1920x1080 slide is ~1 MB as PNG and a fraction of that as JPEG.
set -euo pipefail

cd "$(dirname "$0")"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
SLIDES=${1:-"1 2 3 4 5 6 7"}

# Inline the self-hosted fonts so Chrome's file:// origin rules can't block them.
{
  echo "@font-face{font-family:'Inter';font-style:normal;font-weight:400 700;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/inter-latin.woff2 | tr -d '\n')) format('woff2');}"
  echo "@font-face{font-family:'Jersey 20';font-style:normal;font-weight:400;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/jersey-20-latin.woff2 | tr -d '\n')) format('woff2');}"
} > fonts.css

mkdir -p out
for n in $SLIDES; do
  "$CHROME" \
    --headless=new \
    --disable-gpu \
    --hide-scrollbars \
    --allow-file-access-from-files \
    --force-device-scale-factor=2 \
    --window-size=1920,1080 \
    --virtual-time-budget=5000 \
    --screenshot="out/headdy-promo-$n.png" \
    "file://$PWD/slides.html?s=$n" >/dev/null 2>&1 || true
  # Headless Chrome sometimes exits non-zero after writing the screenshot, which `set -e` would
  # treat as a failed slide. Judge by the file instead.
  [ -s "out/headdy-promo-$n.png" ] || { echo "  FAILED out/headdy-promo-$n.png" >&2; exit 1; }
  # Chrome can return before the PNG is fully on disk, and sips then fails on a truncated file.
  # Wait for the size to stop changing before converting.
  prev=-1; size=0
  for _ in $(seq 1 50); do
    size=$(stat -f %z "out/headdy-promo-$n.png" 2>/dev/null || echo 0)
    [ "$size" -gt 0 ] && [ "$size" = "$prev" ] && break
    prev=$size; sleep 0.2
  done
  # Chrome can only write PNG; downsample and convert in one pass, then drop the PNG.
  sips --resampleWidth 1920 -s format jpeg -s formatOptions 90 \
    "out/headdy-promo-$n.png" --out "out/headdy-promo-$n.jpg" >/dev/null
  rm "out/headdy-promo-$n.png"
  echo "  built out/headdy-promo-$n.jpg"
done
