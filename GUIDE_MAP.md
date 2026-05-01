# EduConnect — guide de la suite (architecture modulaire)

Ce document est une **carte de route** : ordre suggéré, découpage `backend/` + `frontend/`, et jalons vérifiables. À ajuster selon ton calendrier TFC.

---

## Principes

- **Modularité par domaine** (pas obligatoire d’utiliser un package « modules » au début).
- **Backend** : logique + migrations + API par domaine.
- **Frontend** : une feature = un dossier + routes lazy-loadées.
- **Version API** : préfixe ` /api/v1/...` dès que l’auth est en place.

---

## Phase 0 — Socle (déjà en place ou à verrouiller)

| Jalon | Détail |
|--------|--------|
| Environnement | PHP, Composer, Node, PostgreSQL, Redis, Docker (selon besoin) |
| Monorepo | `backend/` (Laravel), `frontend/` (Vue) |
| BDD locale | `educonnect_db`, réf. [`db_auth.md`](db_auth.md) |
| Smoke test | `GET /api/health`, front via proxy Vite → backend |

---

## Phase 1 — Identité & accès (bloquant pour tout le reste)

**Statut : démarré** — API avec **Laravel Sanctum** (jetons personnels type Bearer, proches de l’usage « JWT » côté client).

**Backend (`backend/`)** — déjà en place

- `User` + enum `UserRole` + colonne `role`.  
- `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` + `GET /api/v1/health`.  
- Compte seed : `admin@educonnect.test` / `password` (`php artisan db:seed`).  
- Throttle login : `login` (voir `AppServiceProvider`).

**À faire ensuite dans cette phase**

- Middleware `EnsureRole` : `Route::middleware('role:admin')` ou `role:admin,secretariat`.
- Routes témoins RBAC : `GET /api/v1/admin/ping` (admin seul), `GET /api/v1/staff/ping` (admin + secrétariat).
- **15 tests verts** (auth + RBAC complet).

**Reste optionnel**

- Journalisation des connexions / actions sensibles (table `audit_logs`).
- JWT strict (`tymon/jwt-auth`) si le CDC l’impose à la lettre.

**Frontend (`frontend/`)** — en place (minimal)

- Formulaire de connexion + `sessionStorage` + `composables/useAuth.ts` (Bearer sur `/api/v1/*` via proxy Vite).

**Critère de fin de phase** : middleware RBAC opérationnel, routes protégées par rôle — **TERMINÉE**.

---

## Phase 2 — Référentiels scolaires (sans élèves détaillés, tu peux déjà poser les tables)

Ordre logique des **migrations / domaines** :

1. **Années scolaires & trimestres** (`SchoolYear`, `Term`) — **EN COURS**.
2. **Niveaux / sections / classes** (`Level`, `Section`, `ClassRoom`).
3. **Matières & coefficients** par classe (`Subject`, pivot classe–matière–coef).
4. **Enseignants** (profil lié à `User`) + affectations enseignant ↔ matière ↔ classe.
5. **Emploi du temps** (créneaux, salle, cours) — peut suivre les affectations.

**Ce qui est en place (étape 1)**

- Tables `school_years` (`name`, `starts_on`, `ends_on`, `is_current`) et `terms` (`school_year_id`, `name`, `position`, dates) — cascade à la suppression de l'année.
- API admin `/api/v1/school-years` (CRUD + pagination, `is_current` exclusif) et `/api/v1/terms` (CRUD + filtre `school_year_id`).
- Validation : nom unique d'année, position et nom uniques par année, `ends_on > starts_on`.
- **26 tests verts** (auth + RBAC + school-years + terms).

**API** : CRUD admin (filtré RBAC) sous `/api/v1/...` par ressource.

**Front (en place)** : Vue 3 + TypeScript + Vue Router + Pinia.

- Client API typé (`src/api/client.ts`) avec gestion `401`.
- Store Pinia auth (`src/stores/auth.ts`).
- Router avec gardes (`requiresAuth`, `requiresGuest`, `roles`).
- Layout admin avec sidebar (`src/layouts/AdminLayout.vue`).
- Vues : `LoginView`, `DashboardView`, `SchoolYearsView` (CRUD + définir année courante), `SchoolYearDetailView` (CRUD trimestres), `ForbiddenView`, `NotFoundView`.

**Critère de fin** : créer une année, une classe, une matière avec coefficient, affecter un enseignant.

---

## Phase 3 — Élèves & parents (dossier élève)

1. **Parents** : `User` rôle parent + fiche contact.  
2. **Élèves** : identité, photo (stockage fichier / S3 plus tard), `class_room_id`, lien parent(s).  
3. Import **CSV** (admin) : validation, rapport d’erreurs, pas d’écrasement silencieux.

**Critère de fin** : créer élève, rattacher parent, filtrer par classe / année.

---

## Phase 4 — Notes & bulletins

1. **Évaluations** : type (devoir, contrôle…), date, trimestre.  
2. **Notes** : contrainte 0–20, recalcul moyennes pondérées, log des modifications.  
3. **Bulletins** : agrégation trimestre, PDF (bibliothèque serveur), clôture trimestre (admin).

**Critère de fin** : saisie notes enseignant → moyenne visible côté parent (lecture seule).

---

## Phase 5 — Assiduité & alertes

1. Présences par cours / journée, justifié / non justifié.  
2. Règles CDC : **3 absences injustifiées consécutives** ou **5 sur 30 jours** → notification.  
3. File d’attente (Redis + jobs Laravel) pour emails/SMS.

**Critère de fin** : déclenchement d’au moins une alerte test sur données fictives.

---

## Phase 6 — Communication & pilotage

1. **Messagerie** interne (threads ou message simple + `lu`).  
2. **Notifications** : historique consultable admin.  
3. **Tableaux de bord** : agrégations (effectifs, absences, moyennes) — requêtes + cache Redis si besoin.

---

## Phase 7 — Qualité, sécurité, livraison

- Tests PHPUnit sur règles métier critiques (notes, absences, RBAC).  
- OpenAPI / Scribe pour documenter l’API.  
- `.env.example` sans secrets ; CI (lint + tests) si dépôt Git partagé.  
- Docker Compose « prod-like » (optionnel TFC).

---

## Arborescence cible (suggestion)

**Laravel — sous `app/`**

```text
app/Domain/
  Auth/
  SchoolYear/
  Organization/    # niveaux, classes
  Academics/       # matières, cours, emploi du temps
  People/          # élèves, parents, enseignants
  Grades/          # notes, évaluations
  Attendance/
  ReportCards/     # bulletins, PDF
  Messaging/
  Shared/          # traits, enums, value objects
```

**Vue — sous `src/`**

```text
src/features/
  auth/
  admin-school/
  students/
  grades/
  attendance/
  messaging/
src/shared/
  api/
  components/
  composables/
```

---

## Prochaine action concrète (recommandée)

1. Décider **JWT vs Sanctum** (une phrase dans le README équipe ou ici en commentaire).  
2. Implémenter **Phase 1** jusqu’au critère « login + me + rôle ».  
3. Introduire le préfixe **`/api/v1`** et déplacer `health` si besoin.

---

*Document vivant — à mettre à jour quand une phase est terminée ou repriorisée.*
