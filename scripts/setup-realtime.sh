#!/usr/bin/env bash
# =============================================================================
#  EduConnect — configure & vérifie le temps réel (Reverb / WebSocket) en prod.
#  À lancer sur le VPS, à la racine du projet :  ./scripts/setup-realtime.sh
#
#  Ce que fait le script :
#   1. lit REVERB_APP_KEY / APP_URL dans backend/.env
#   2. (re)génère frontend/.env.production avec les bonnes valeurs WebSocket
#   3. rebuild le frontend (les VITE_* sont gravées au build)
#   4. diagnostique : services, port 8080, proxy nginx /app, handshake
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

get_env() { grep -E "^$1=" "$BACKEND/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'; }

RKEY="$(get_env REVERB_APP_KEY)"
BROADCAST="$(get_env BROADCAST_CONNECTION)"
APP_URL="$(get_env APP_URL)"
HOST="$(printf '%s' "$APP_URL" | sed -E 's#^https?://##; s#/.*$##')"
[ -z "$HOST" ] && HOST="educonnect.school"

echo "── Backend ───────────────────────────────────────────"
echo "  BROADCAST_CONNECTION = ${BROADCAST:-<vide>}"
echo "  REVERB_APP_KEY       = ${RKEY:0:8}…"
echo "  HOST (depuis APP_URL)= $HOST"
[ -z "$RKEY" ] && { echo "✗ REVERB_APP_KEY absent dans backend/.env — abandon."; exit 1; }
[ "$BROADCAST" != "reverb" ] && echo "⚠ BROADCAST_CONNECTION devrait valoir 'reverb'."

echo "── Écriture frontend/.env.production ─────────────────"
cat > "$FRONTEND/.env.production" <<EOF
VITE_REVERB_APP_KEY=$RKEY
VITE_REVERB_HOST=$HOST
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
EOF
sed 's/^/  /' "$FRONTEND/.env.production"

echo "── Build frontend ────────────────────────────────────"
( cd "$FRONTEND" && npm run build >/tmp/educonnect-build.log 2>&1 ) \
  && echo "  build OK" \
  || { echo "✗ build échoué — voir /tmp/educonnect-build.log"; tail -20 /tmp/educonnect-build.log; exit 1; }

echo "── Diagnostic serveur ────────────────────────────────"
for s in educonnect-reverb educonnect-queue nginx; do
  printf "  %-20s : %s\n" "$s" "$(systemctl is-active "$s" 2>/dev/null || echo inconnu)"
done

if ss -tlnp 2>/dev/null | grep -q ':8080'; then
  echo "  Reverb écoute :8080 : oui"
else
  echo "  ⚠ Reverb n'écoute PAS sur :8080 — 'systemctl restart educonnect-reverb'"
fi

NGINX_CONF="/etc/nginx/sites-enabled/educonnect.conf"
[ -f "$NGINX_CONF" ] || NGINX_CONF="/etc/nginx/sites-available/educonnect.conf"
if grep -q "location /app" "$NGINX_CONF" 2>/dev/null; then
  echo "  nginx location /app : présent"
else
  echo "  ⚠ nginx location /app ABSENT dans $NGINX_CONF"
  echo "    -> recopie le modèle : cp $ROOT/deploy/nginx/educonnect.conf $NGINX_CONF"
  echo "       (adapte server_name + socket PHP), puis: nginx -t && systemctl reload nginx"
fi

echo "  Handshake public /app :"
curl -s -o /dev/null -w "    https://$HOST/app/$RKEY -> HTTP %{http_code} (101/200/426 = ok, 404/502 = souci)\n" \
  --max-time 8 "https://$HOST/app/$RKEY" || echo "    (curl indisponible)"

echo ""
echo "✅ Terminé. Recharge https://$HOST (ferme/rouvre la PWA), puis teste un message."
echo "   F12 → Réseau : une connexion wss://$HOST/app/... en 101 = temps réel OK."
