# Product

## Register

product

## Users

- **Administration / secrétariat** : configuration de l'école, années scolaires, classes, utilisateurs, bulletins, messagerie de masse.
- **Enseignants** : notes, évaluations, absences, messagerie.
- **Parents** : suivi des enfants (bulletin, assiduité, messagerie) — usage majoritairement **smartphone**.
- **Élèves** : bulletin, absences, emploi du temps, messagerie — usage majoritairement **smartphone**.

## Product Purpose

Application de gestion scolaire (JEFF TFC / EduConnect) pour un établissement au Congo. Centraliser la vie scolaire : structure, notes, bulletins, présences, communication école–famille. Succès = tâches quotidiennes rapides sur mobile pour familles et élèves, et efficacité pour le staff sur bureau.

## Brand Personality

Sérieux, clair, rassurant. Ton institutionnel mais accessible (pas froid, pas startup flashy). Interface en **français**. Priorité à la lisibilité des données scolaires (notes, absences, messages).

## Anti-references

- Dashboard « AI slop » : Inter partout sans raison, dégradés violet–bleu, cartes dans des cartes, glassmorphism.
- Desktop-first sur les portails élève/parent (boutons trop petits, tableaux non adaptés).
- Jargon technique visible pour les familles.
- Couleurs de statut illisibles (gris sur fond coloré).

## Design Principles

1. **Mobile-first** pour portails élève et parent (zones tactiles ≥ 44px, une tâche par écran quand pertinent).
2. **Données d'abord** : hiérarchie claire (moyenne, statut, date) avant décoration.
3. **Cohérence** : réutiliser les tokens CSS existants (`frontend/src/style.css`).
4. **Accessibilité** : contrastes suffisants, focus visible, `prefers-reduced-motion` respecté.
5. **Confiance** : états vides et erreurs explicites en français simple.

## Accessibility & Inclusion

WCAG 2.1 AA visé. Contrastes vérifiés sur textes et badges. Navigation clavier et focus visible. Libellés de boutons explicites (pas d'icône seule sans label accessible).
