# Base de données — EduConnect (référence locale)

## PostgreSQL

| Paramètre | Valeur habituelle (dev local) |
|-----------|-------------------------------|
| Hôte | `127.0.0.1` |
| Port | `5432` |
| Base | `educonnect_db` |
| Utilisateur | `educonnect_user` |
| Mot de passe | défini dans [`backend/.env`](backend/.env) → `DB_PASSWORD` |

### Connexion en ligne de commande

```bash
psql -h 127.0.0.1 -p 5432 -U educonnect_user -d educonnect_db
```

### pgAdmin (Query Tool / enregistrer un serveur)

- **Server name** : libre (ex. `EduConnect local`)
- **Host** : `127.0.0.1`
- **Port** : `5432`
- **Database** : `educonnect_db`
- **Username** : `educonnect_user`
- **Password** : identique à `DB_PASSWORD` dans `backend/.env`

### Laravel

Les variables `DB_*` dans `backend/.env` pilotent la connexion (`php artisan migrate`, etc.).

---

## Redis (cache / files d’attente — plus tard)

| Paramètre | Valeur habituelle |
|-----------|-------------------|
| Hôte | `127.0.0.1` |
| Port | `6379` |
| Mot de passe | souvent vide en local (`REDIS_PASSWORD` dans `backend/.env`) |

---

**Sécurité** : ne pas versionner de vrais mots de passe dans ce fichier ; garder les secrets uniquement dans `backend/.env` (déjà ignoré par Git).
