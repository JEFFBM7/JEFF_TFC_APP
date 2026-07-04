#!/usr/bin/env bash
# =============================================================================
#  EduConnect — déploiement / mise à jour sur le VPS.
#  À lancer depuis la racine du projet sur le serveur : ./scripts/deploy.sh
#  (Pré-requis : repo cloné, backend/.env et frontend/.env.production déjà posés,
#   services systemd educonnect-reverb / educonnect-queue déjà installés.)
# =============================================================================
set -euo pipefail

# Le VPS exécute en root : autorise Composer (sinon plugins/scripts désactivés + warnings).
export COMPOSER_ALLOW_SUPERUSER=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# Vrai si l'unité systemd <nom>.service existe.
unit_exists() { systemctl cat "$1.service" >/dev/null 2>&1; }

# Génère les clés VAPID (Web Push) dans backend/.env si absentes.
# ⚠ Une seule fois : les régénérer invaliderait tous les abonnements push existants.
ensure_vapid_keys() {
  if [[ -n "$(grep -E '^VAPID_PUBLIC_KEY=.+' "$BACKEND/.env" 2>/dev/null || true)" ]]; then
    echo "→ Clés VAPID déjà présentes"
    return
  fi
  echo "→ Génération des clés VAPID (Web Push)"
  local keys pub priv host
  keys="$(cd "$BACKEND" && php -r 'require "vendor/autoload.php"; $k=Minishlink\WebPush\VAPID::createVapidKeys(); echo $k["publicKey"]."\n".$k["privateKey"];')"
  pub="$(printf '%s\n' "$keys" | sed -n 1p)"
  priv="$(printf '%s\n' "$keys" | sed -n 2p)"
  host="$(grep -E '^APP_URL=' "$BACKEND/.env" 2>/dev/null | cut -d= -f2- | sed -E 's#^https?://##; s#/.*$##')"
  [[ -z "$host" ]] && host="educonnect.school"
  {
    echo ""
    echo "# Web Push (notifications) — généré au déploiement"
    echo "VAPID_PUBLIC_KEY=${pub}"
    echo "VAPID_PRIVATE_KEY=${priv}"
    echo "VAPID_SUBJECT=mailto:admin@${host}"
  } >> "$BACKEND/.env"
  echo "   clés VAPID écrites dans backend/.env"
}

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
ensure_vapid_keys                                   # clés Web Push (avant config:cache)

# Mode maintenance le temps des migrations/caches ; remise en ligne garantie
# même si une étape échoue (trap EXIT).
echo "→ Mode maintenance"
php artisan down --retry=15 || true
trap 'cd "$BACKEND" && php artisan up >/dev/null 2>&1 || true' EXIT

php artisan migrate --force
php artisan storage:link >/dev/null 2>&1 || true   # déjà présent = sans gravité
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

echo "→ Sortie du mode maintenance"
cd "$BACKEND" && php artisan up

echo "✅ Déploiement terminé."
