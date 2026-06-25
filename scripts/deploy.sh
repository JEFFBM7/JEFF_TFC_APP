#!/usr/bin/env bash
# =============================================================================
#  EduConnect — déploiement / mise à jour sur le VPS.
#  À lancer depuis la racine du projet sur le serveur : ./scripts/deploy.sh
#  (Pré-requis : repo cloné, backend/.env et frontend/.env.production déjà posés,
#   services systemd educonnect-reverb / educonnect-queue déjà installés.)
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"
PHP_FPM="${PHP_FPM_SERVICE:-php8.3-fpm}"   # adapter à ta version PHP

echo "→ Récupération du code"
git -C "$ROOT" pull --ff-only

echo "→ Backend : dépendances + migrations + caches"
cd "$BACKEND"
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan storage:link 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "→ Frontend : build de production"
cd "$FRONTEND"
npm ci
npm run build

echo "→ Redémarrage des services"
sudo systemctl restart educonnect-reverb educonnect-queue "$PHP_FPM"
sudo systemctl reload nginx

echo "✅ Déploiement terminé."
