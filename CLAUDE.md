---
description: 
alwaysApply: true
---

---
name: claude-md
description: Project documentation for the JEFF TFC application
metadata:
  type: reference
---

# JEFF TFC Application

## Overview
The repository follows a classic **frontend / backend** split:
- **frontend** – a Vue 3 application (see `frontend/package.json`). It uses Pinia for state management and follows the standard Vue‑CLI project layout.
- **backend** – a PHP Laravel (or Lumen) API (see `backend/composer.json`). It provides the REST endpoints consumed by the frontend.

Both sides are version‑controlled together to keep API contracts in sync.

## Directory structure
```
JEFF_TFC_APP/
├─ backend/            # PHP API
│   ├─ app/            # Controllers, Models, Requests, Resources, Providers
│   ├─ database/       # Factories, migrations, seeders
│   ├─ routes/         # api.php, web.php
│   ├─ tests/          # Feature tests for the API
│   ├─ composer.json   # PHP dependencies
│   └─ README.md
├─ frontend/           # Vue 3 SPA
│   ├─ src/            # Components, layouts, router, API client
│   ├─ public/         # index.html and assets
│   ├─ package.json    # JavaScript dependencies
│   └─ README.md
└─ CLAUDE.md           # This documentation file
```

## Key technologies
- **Vue 3** (3.4.13) – UI framework.
- **Pinia** – Store management for Vue 3.
- **Laravel** (or similar) – PHP framework for the API.
- **Composer** – PHP dependency manager.
- **npm / Yarn** – JavaScript package manager for the frontend.

## Build & run
### Backend
```bash
cd backend
composer install
php artisan serve   # default http://127.0.0.1:8000
```

### Frontend
```bash
cd frontend
npm install
npm run dev         # starts Vite dev server (http://localhost:5173)
```

The two servers communicate via the API defined in `backend/routes/api.php`.

## Testing
- Backend tests: `php artisan test` (or `vendor/bin/phpunit`).
- Frontend tests (if present): run `npm run test`.

## Environment variables
Both sides rely on `.env` files. Typical variables include:
- `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (backend)
- `VITE_API_BASE_URL` – base URL for the API used by the Vue client.

## Contribution guidelines
1. **Branching** – create a feature branch off `main`.
2. **Commit style** – use conventional commits (`feat:`, `fix:`, `docs:` ...).
3. **Lint / format** – run `npm run lint` (frontend) and `php artisan lint` (if configured) before pushing.
4. **Pull request** – ensure CI passes (backend tests + frontend build).

---
*Generated with Claude Code*
