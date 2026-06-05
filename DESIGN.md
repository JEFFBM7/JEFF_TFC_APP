# Design

## Stack

- Frontend : Vue 3, CSS variables globales dans `frontend/src/style.css`
- Layout admin : `frontend/src/layouts/AdminLayout.vue`
- Portails élève/parent : navigation basse fixe, contenu max ~36rem centré sur mobile

## Color

- Primary : `#3457ff` (bleu institutionnel), soft `#eef3ff`, tint `#f7f9ff`
- Text : `#101828` / soft `#667085` / muted `#98a2b3`
- Background : `#f6f8fc`, cards `#ffffff`, subtle `#f9fbff`
- Border : `#dfe5f2` / strong `#cfd9ea`
- Semantic : success `#039855`, warn `#dc6803`, danger `#d92d20` (+ variantes soft)

## Typography

- Famille : Inter, system-ui fallback
- Base : 15px, line-height 1.5
- Hiérarchie par taille/poids (pas de gris trop clair sur fond coloré)

## Spacing & layout

- Radius : `8px` (`--radius`)
- Ombres légères : `--shadow`, `--shadow-card`
- Grilles mobile portail : **2 colonnes** pour métriques (Coeff. / Éval., Points en pleine largeur)
- Messagerie portail : liste ↔ fil (pas deux panneaux empilés sur téléphone)

## Components

- Boutons : `.btn-primary`, `.btn-sm`, pills `.score-pill` (success/warn/danger)
- Cartes : bordure + `var(--bg-card)` + ombre légère
- Badges : `.badge`, `.badge-warn`, etc.

## Motion

- Transitions courtes, discrètes
- Respecter `prefers-reduced-motion`

## Registers in app

- **Product UI** : toute l'app (admin, enseignant, parent, élève) — pas de landing marketing séparée dans ce dépôt.
