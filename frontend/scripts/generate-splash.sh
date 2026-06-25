#!/usr/bin/env bash
#
# Génère les écrans de démarrage (splash) iOS pour la PWA.
# Logo EduConnect centré sur le dégradé bleu marine de l'app, sans tuile blanche.
#
# Dépendances : ImageMagick (convert).
# Usage : ./frontend/scripts/generate-splash.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOGO="${ROOT}/src/assets/logo-educonnect-full.png"
OUT="${ROOT}/public/pwa/splash"

# Dégradé (haut → bas) et part de la largeur occupée par le logo.
GRAD_TOP="#14306e"
GRAD_BOTTOM="#060e22"
LOGO_WIDTH_RATIO=66   # % de la largeur de l'écran

# Toutes les tailles référencées dans index.html (apple-touch-startup-image).
SIZES=(
  640x1136 750x1334 828x1792 1125x2436 1170x2532 1179x2556 1206x2622
  1242x2208 1242x2688 1284x2778 1290x2796 1320x2868 1536x2048 1620x2160
  1668x2224 1668x2388 2048x2732
)

mkdir -p "$OUT"

for size in "${SIZES[@]}"; do
  W="${size%x*}"
  H="${size#*x}"
  logo_w=$(( W * LOGO_WIDTH_RATIO / 100 ))

  convert -size "${W}x${H}" gradient:"${GRAD_TOP}"-"${GRAD_BOTTOM}" \
    \( "$LOGO" -resize "${logo_w}x" \) \
    -gravity center -composite \
    -strip "${OUT}/splash-${size}.png"

  echo "✓ splash-${size}.png"
done

echo "Terminé : ${#SIZES[@]} écrans générés dans ${OUT}"
