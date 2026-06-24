#!/usr/bin/env bash
# Démarre API Laravel + Reverb (WebSocket) + queue + frontend Vite.
#
# Usage :
#   ./scripts/dev-stack.sh            → mode dev (Vite hot-reload sur :5173)
#   ./scripts/dev-stack.sh --tunnel   → build prod + preview (:4173) + tunnel
#                                        HTTPS public (cloudflared) pour tester
#                                        l'app complète + l'installation PWA sur
#                                        un mobile réel.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

MODE="dev"
case "${1:-}" in
  --tunnel|tunnel|--pwa|pwa) MODE="tunnel" ;;
  "") ;;
  *) echo "Argument inconnu : $1 (attendu : --tunnel)"; exit 1 ;;
esac

LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

ensure_backend_env() {
  if [[ ! -f "$BACKEND/.env" ]]; then
    echo "→ Création de backend/.env depuis .env.example"
    cp "$BACKEND/.env.example" "$BACKEND/.env"
  fi

  if ! grep -q '^APP_KEY=base64:' "$BACKEND/.env" 2>/dev/null; then
    echo "→ Génération de APP_KEY"
    (cd "$BACKEND" && php artisan key:generate --force --ansi)
  fi

  if ! grep -q '^BROADCAST_CONNECTION=reverb' "$BACKEND/.env"; then
    echo "→ BROADCAST_CONNECTION=reverb"
    if grep -q '^BROADCAST_CONNECTION=' "$BACKEND/.env"; then
      sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=reverb/' "$BACKEND/.env"
    else
      echo 'BROADCAST_CONNECTION=reverb' >> "$BACKEND/.env"
    fi
  fi

  echo "→ Migrations base de données"
  (cd "$BACKEND" && php artisan migrate --force --ansi)

  if ! (cd "$BACKEND" && php artisan tinker --execute="exit(App\\Models\\User::query()->exists() ? 0 : 1);" >/dev/null 2>&1); then
    echo "→ Seed initial (comptes de démo)"
    (cd "$BACKEND" && php artisan db:seed --force --ansi)
  fi
}

ensure_frontend_env() {
  if [[ ! -f "$FRONTEND/.env" ]]; then
    echo "→ Création de frontend/.env depuis .env.example"
    cp "$FRONTEND/.env.example" "$FRONTEND/.env"
  fi
}

check_deps() {
  command -v php >/dev/null || { echo "Erreur: php introuvable"; exit 1; }
  command -v npm >/dev/null || { echo "Erreur: npm introuvable"; exit 1; }
  [[ -d "$BACKEND/vendor" ]] || { echo "→ composer install (backend)"; (cd "$BACKEND" && composer install --no-interaction); }
  [[ -d "$FRONTEND/node_modules" ]] || { echo "→ npm install (frontend)"; (cd "$FRONTEND" && npm install); }
}

build_frontend() {
  echo "→ Build de production du frontend (génère le service worker PWA)"
  (cd "$FRONTEND" && npm run build)
}

print_banner() {
  echo ""
  echo "══════════════════════════════════════════════════════════"
  if [[ "$MODE" == "tunnel" ]]; then
    echo "  EduConnect — stack tunnel PWA (API + Reverb + preview + cloudflared)"
    echo "══════════════════════════════════════════════════════════"
    echo "  PC      → http://localhost:4173"
    echo "  API     → http://localhost:8000  (proxy via preview /api)"
    echo "  Reverb  → ws://localhost:8080"
    echo "  Mobile  → l'URL https://*.trycloudflare.com affichée par"
    echo "            le panneau « tunnel » ci-dessous → ouvrir sur le tél."
    echo "            (login + données OK ; installation PWA proposée)"
    echo "  NB      → messagerie temps réel (WebSocket) indisponible via tunnel"
  else
    echo "  EduConnect — stack dev (API + Reverb + Vite)"
    echo "══════════════════════════════════════════════════════════"
    echo "  PC      → http://localhost:5173"
    echo "  API     → http://localhost:8000"
    echo "  Reverb  → ws://localhost:8080"
    if [[ -n "$LAN_IP" ]]; then
      echo "  Mobile  → http://${LAN_IP}:5173  (WebSocket ws://${LAN_IP}:8080)"
    fi
  fi
  echo "  Arrêt   → Ctrl+C"
  echo "══════════════════════════════════════════════════════════"
  echo ""
}

ensure_backend_env
ensure_frontend_env
check_deps
[[ "$MODE" == "tunnel" ]] && build_frontend
print_banner

cd "$BACKEND"

if ! command -v concurrently >/dev/null 2>&1 && [[ ! -x "$BACKEND/node_modules/.bin/concurrently" ]]; then
  echo "→ Installation de concurrently (backend)"
  npm install --no-save concurrently >/dev/null
fi

CONCURRENTLY="$BACKEND/node_modules/.bin/concurrently"
if [[ ! -x "$CONCURRENTLY" ]]; then
  CONCURRENTLY="npx concurrently"
fi

if [[ "$MODE" == "tunnel" ]]; then
  exec "$CONCURRENTLY" \
    -c "blue,magenta,green,yellow,cyan" \
    --names "api,reverb,queue,preview,tunnel" \
    --kill-others \
    "php artisan serve --host=0.0.0.0 --port=8000" \
    "php artisan reverb:start --host=0.0.0.0 --port=8080" \
    "php artisan queue:listen --tries=1 --timeout=0" \
    "npm run preview --prefix \"$FRONTEND\" -- --host 0.0.0.0 --port 4173" \
    "npx -y cloudflared tunnel --url http://localhost:4173"
fi

exec "$CONCURRENTLY" \
  -c "blue,magenta,green,yellow" \
  --names "api,reverb,queue,vite" \
  --kill-others \
  "php artisan serve --host=0.0.0.0 --port=8000" \
  "php artisan reverb:start --host=0.0.0.0 --port=8080" \
  "php artisan queue:listen --tries=1 --timeout=0" \
  "npm run dev --prefix \"$FRONTEND\" -- --host 0.0.0.0 --port 5173"
