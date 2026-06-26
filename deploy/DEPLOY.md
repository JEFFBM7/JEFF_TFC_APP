# Déploiement EduConnect — VPS Hostinger (Ubuntu)

Architecture : **une seule origine** `https://educonnect.school`
- `/` → frontend Vue (build `frontend/dist`)
- `/api`, `/broadcasting`, `/sanctum`, `/storage` → API Laravel (PHP-FPM)
- `/app` → WebSocket Reverb (messagerie temps réel)

> Pourquoi un VPS et pas le mutualisé : le temps réel (Reverb) + la file d'attente
> ont besoin de **processus permanents** (impossible en hébergement mutualisé).

Remplace partout `educonnect.school` par ton domaine et `/var/www/educonnect`
par ton chemin réel.

---

## 0. Commander le VPS
- Hostinger → **VPS KVM** (KVM 1 suffit pour démarrer), OS **Ubuntu 24.04**.
- Pointe ton domaine (enregistrement **A**) vers l'IP du VPS.
- Connecte-toi : `ssh root@IP_DU_VPS`.

## 1. Paquets système
```bash
apt update && apt upgrade -y
apt install -y nginx postgresql git unzip curl \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd
# Composer
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
# Node 20 (pour builder le frontend)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs
```
> Vérifie ta version PHP : `php -v`. Si ce n'est pas 8.3, adapte `php8.3` partout
> (fichier Nginx, services systemd, `scripts/deploy.sh`).

## 2. Base de données PostgreSQL
```bash
sudo -u postgres psql -c "CREATE ROLE educonnect LOGIN PASSWORD 'change_me_strong';"
sudo -u postgres psql -c "CREATE DATABASE educonnect OWNER educonnect ENCODING 'UTF8';"
```

## 3. Récupérer le code
```bash
mkdir -p /var/www && cd /var/www
git clone <URL_DE_TON_REPO> educonnect
cd educonnect
chown -R www-data:www-data /var/www/educonnect
```

## 4. Configurer le backend
```bash
cd /var/www/educonnect/backend
cp ../deploy/env/backend.env.production.example .env
nano .env        # DB_PASSWORD, APP_URL, secrets REVERB_*, SMTP...
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force     # comptes initiaux (1re mise en ligne uniquement)
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
```
> Génère des secrets Reverb uniques, ex. :
> `REVERB_APP_KEY=$(openssl rand -hex 16)` et `REVERB_APP_SECRET=$(openssl rand -hex 32)`.

## 5. Configurer + builder le frontend
```bash
cd /var/www/educonnect/frontend
cp ../deploy/env/frontend.env.production.example .env.production
nano .env.production    # VITE_REVERB_APP_KEY = MÊME valeur que REVERB_APP_KEY backend
                        # VITE_REVERB_HOST = ton domaine
npm ci
npm run build           # génère frontend/dist (+ service worker PWA)
```

## 6. Nginx
```bash
cp /var/www/educonnect/deploy/nginx/educonnect.conf /etc/nginx/sites-available/educonnect.conf
nano /etc/nginx/sites-available/educonnect.conf   # server_name + chemins + version PHP
ln -s /etc/nginx/sites-available/educonnect.conf /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

## 7. HTTPS (Let's Encrypt)
```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d educonnect.school
```
Certbot ajoute automatiquement le bloc HTTPS (443) et la redirection 80→443.
**Indispensable** : sans HTTPS, l'installation PWA et le splash ne sont pas proposés.

## 8. Services permanents (Reverb + queue)
```bash
cp /var/www/educonnect/deploy/systemd/educonnect-reverb.service /etc/systemd/system/
cp /var/www/educonnect/deploy/systemd/educonnect-queue.service  /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now educonnect-reverb educonnect-queue
systemctl status educonnect-reverb --no-pager
```

## 8 bis. Notifications Web Push (messages)
Génère des clés VAPID uniques et mets-les dans `backend/.env` :
```bash
cd /var/www/educonnect/backend
php artisan tinker --execute='print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
# -> copie publicKey/privateKey dans VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY de .env
php artisan migrate --force   # crée la table push_subscriptions
```
- Le **worker de queue** (étape 8) envoie les pushs : il doit tourner.
- Côté navigateur, l'utilisateur active via le bouton 🔔 (topbar). **iOS** : uniquement
  en **PWA installée** (iOS 16.4+), et l'activation doit venir d'un **geste** (le bouton).
- Le frontend récupère la clé publique via `/api/v1/push/public-key` (aucune variable VITE).

## 9. Caches Laravel (perf prod)
```bash
cd /var/www/educonnect/backend
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

---

## ✅ Vérifications
- `https://educonnect.school` s'ouvre (cadenas vert).
- Connexion : `admin@educonnect.test` / `password` (ou un compte réel).
- Sur iPhone/Android : Partager/menu → **installer l'app** (icône EduConnect + splash).
- Messagerie temps réel : ouvre deux sessions, un message apparaît sans recharger.
- Logs si souci : `journalctl -u educonnect-reverb -f` et `tail -f backend/storage/logs/laravel.log`.

## 🔁 Mises à jour suivantes
Une seule commande (après `git push` côté dev) :
```bash
cd /var/www/educonnect && ./scripts/deploy.sh
```

## 🩺 Dépannage rapide
| Symptôme | Piste |
|---|---|
| 502 Bad Gateway | mauvaise version PHP dans le `fastcgi_pass` (socket) — `ls /run/php/` |
| API 404 sur `/api` | bloc `location ~ ^/(api...)` ou racine backend incorrecte |
| Pas de temps réel | `systemctl status educonnect-reverb` ; clé `VITE_REVERB_APP_KEY` ≠ backend ; `/app` non proxifié |
| Login échoue après reseed | les mots de passe élève générés changent — utiliser le compte admin seedé |
| PWA non proposée | vérifier HTTPS actif + `dist/sw.js` présent + manifeste chargé |
