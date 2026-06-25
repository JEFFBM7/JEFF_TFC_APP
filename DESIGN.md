# Design

## Stack

- Frontend : Vue 3, variables CSS globales dans `frontend/src/style.css`
- Layout admin : `frontend/src/layouts/AdminLayout.vue`
- Portails élève/parent : navigation basse fixe, contenu centré sur mobile
- Styles partagés portail : `frontend/src/styles/portal-*.css`, `messages-staff.css`

## Design System — Dark Navy

Thème unique **sombre (navy)** appliqué à toute l'application (admin, enseignant,
parent, élève). Inspiré de la palette de la page de connexion. Il n'existe pas de
thème clair : toutes les surfaces doivent utiliser les tokens ci-dessous, jamais
de couleurs claires codées en dur.

### Color Palette (tokens — source de vérité : `style.css`)

| Rôle | Variable CSS | Valeur |
|---|---|---|
| Texte principal | `--text` | `#e2eaf8` |
| Texte secondaire | `--text-soft` | `#8aadcf` |
| Texte muet | `--text-muted` | `#4a6a90` |
| Fond global | `--bg` | `#050d1f` |
| Carte / surface | `--bg-card` | `#0d1f4a` |
| Surface subtile | `--bg-subtle` | `#0f2455` |
| Surface douce | `--bg-soft` | `#0a1836` |
| Bordure | `--border` | `rgba(255,255,255,0.08)` |
| Bordure forte | `--border-strong` | `rgba(255,255,255,0.15)` |
| Primary | `--primary` | `#3b82f6` |
| Primary hover | `--primary-hover` | `#2563eb` |
| Primary soft (fond) | `--primary-soft` | `rgba(59,130,246,0.15)` |
| Primary tint (bordure) | `--primary-tint` | `rgba(59,130,246,0.25)` |
| Primary dark | `--primary-dark` | `#1d4ed8` |
| Accent / highlight | `--accent` | `#60a5fa` |
| Danger | `--danger` / `--danger-soft` | `#f87171` / `rgba(248,113,113,0.12)` |
| Succès | `--success` / `--success-soft` | `#4ade80` / `rgba(74,222,128,0.12)` |
| Avertissement | `--warn` / `--warn-soft` | `#fbbf24` / `rgba(251,191,36,0.12)` |

### Couleur d'accent spéciale

- **Tertiaire / violet** : `#c8a0f0` sur fond `rgba(192,160,240,0.15)` — réservé aux
  badges de rôle distincts (ex. badge « parent »).

### Conventions de tokens

- **Surfaces** : `--bg-card` (cartes), `--bg-subtle` / `--bg-soft` (panneaux
  internes, champs en lecture seule, hover de lignes).
- **Pills / badges de statut** : fond `--*-soft` + texte `--*` (solide).
  Ex. succès = `background: var(--success-soft); color: var(--success)`.
- **États sélectionnés / info** : fond `--primary-soft`, bordure `--primary-tint`,
  texte `--accent`.
- **Bordures sémantiques translucides** : `rgba(74,222,128,0.3)` (succès),
  `rgba(251,191,36,0.3)` (warn), `rgba(248,113,113,0.3)` (danger).

## Exceptions assumées (ne pas convertir en tokens)

- **Hero du tableau de bord admin** (`DashboardView.vue`) : palette éditoriale
  autonome — dégradés navy + accents dorés (`--admin-gold #c9a227`, médailles
  or/argent/bronze). Indépendante des tokens globaux.
- **Page de connexion** (`LoginView.vue`) : palette sombre native d'origine.
- **Gabarits d'impression** (ex. fiche d'inscription dans `StudentsView.vue`,
  ouverts dans une fenêtre `window.open`) : CSS **papier blanc** (`#fff`, etc.).
  Les `var(--…)` n'y sont pas résolues → garder des couleurs littérales.
- **Composants de bulletin** (`components/bulletin/*`) : surfaces document.

## Typography

- Famille : **Inter**, fallback system-ui
- Base : 15px, line-height 1.5
- Titres : `h1` 1.5rem/800, `h2` 1.12rem/700, `h3` 1rem/600

## Spacing & layout

- Radius : `10px` (`--radius`)
- Ombres sombres : `--shadow`, `--shadow-card`, `--shadow-hover`
- Cibles tactiles ≥ 2.6rem sur mobile (`max-width: 720px`)
- Grilles métriques portail : 2 colonnes sur mobile
- Messagerie portail : liste ↔ fil (jamais deux panneaux empilés sur téléphone)

## Components

- Boutons : `.btn-primary` (gradient sky), `.btn-secondary`, `.btn-danger`, `.btn-sm`
- Cartes : `.card` = `--bg-card` + `--border` + `--shadow-card`
- Badges : `.badge`, `.badge-warn`, `.badge-danger`, `.badge-success`, `.badge-muted`
- Tables : en-tête `--bg-soft`, hover ligne `rgba(255,255,255,0.03)`
- Nav active : accent bleu + fond `--primary-soft`

## Motion

- Transitions courtes (0.15–0.2s), discrètes
- Respecter `prefers-reduced-motion`

## Registers in app

**Product UI** uniquement (admin, enseignant, parent, élève) — pas de landing
marketing séparée dans ce dépôt.
