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

# Vrai si l'unité systemd <nom>.service existe.
unit_exists() { systemctl cat "$1.service" >/dev/null 2>&1; }

# Détecte le service PHP-FPM : 1) variable PHP_FPM_SERVICE si fournie,
# 2) version du binaire `php` actif (ex. 8.4 -> php8.4-fpm),
# 3) à défaut, le service php*-fpm le plus récent installé, 4) sinon php-fpm.
detect_php_fpm() {
  if [[ -n "${PHP_FPM_SERVICE:-}" ]]; then echo "$PHP_FPM_SERVICE"; return; fi
  local ver
  ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  if [[ -n "$ver" ]] && unit_exists "php${ver}-fpm"; then echo "php${ver}-fpm"; return; fi
  local svc
  svc="$(systemctl list-unit-files --type=service 2>/dev/null \
    | grep -oE 'php[0-9]+\.[0-9]+-fpm\.service' | sort -V | tail -1 | sed 's/\.service$//')"
  if [[ -n "$svc" ]]; then echo "$svc"; return; fi
  echo "php-fpm"
}

PHP_FPM="$(detect_php_fpm)"
echo "→ PHP-FPM détecté : $PHP_FPM"

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
sudo systemctl restart educonnect-reverb educonnect-queue
if unit_exists "$PHP_FPM"; then
  sudo systemctl reload "$PHP_FPM"   # recharge le code PHP (vide l'OPcache)
else
  echo "⚠ Service PHP-FPM '$PHP_FPM' introuvable : recharge-le manuellement."
fi
sudo systemctl reload nginx

echo "✅ Déploiement terminé."
