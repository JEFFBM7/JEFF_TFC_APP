# Plan B+A — Structure Année Scolaire par Cycle

## Objectif

Implémenter la logique différenciée Primaire/Secondaire :
- **Primaire / Maternel / CTEB** → 3 **Trimestres** + 6 Périodes (positions 1–6)  
- **Secondaire** → 2 **Semestres** + 4 Périodes (positions 1–4)

Et **générer automatiquement** ces structures à la création d'une `SchoolYear`.

## Décision de design clé

> [!IMPORTANT]
> **Une seule `SchoolYear` pour tout l'établissement** — on ne recrée pas de schéma multi-année.  
> Les `Terms` sont **deux jeux distincts** dans la même année : un jeu de 3 trimestres (primaire), un jeu de 2 semestres (secondaire).  
> Chaque term a un `term_type` ET un `applicable_cycle` pour savoir à quel cycle il appartient.  
> Les évaluations et bulletins référencent le `term_id` adapté à la classe (primaire → trimestre, secondaire → semestre).

## Structure finale de l'année scolaire

```
SchoolYear "2025-2026"
├── Term "Trimestre 1" (type=trimestre, cycle=primaire)  →  Période 1 + Période 2
├── Term "Trimestre 2" (type=trimestre, cycle=primaire)  →  Période 3 + Période 4
├── Term "Trimestre 3" (type=trimestre, cycle=primaire)  →  Période 5 + Période 6
├── Term "Semestre 1"  (type=semestre,  cycle=secondaire) →  Période 1 + Période 2
└── Term "Semestre 2"  (type=semestre,  cycle=secondaire) →  Période 3 + Période 4
```

## User Review Required

> [!WARNING]
> **Impact sur les données existantes** : La migration ajoutera `term_type` et `applicable_cycle` à tous les `terms` existants. Les 3 trimestres actuels seront marqués `type=trimestre, cycle=primaire`. Il faudra **créer manuellement** les 2 semestres secondaires via un seeder ou l'interface.
>
> **Le Seeder DevSeeder** sera mis à jour pour créer les 5 terms automatiquement. Si la base est rechargée, tout sera correct. Pour une base existante : une migration de backfill sera incluse.

> [!NOTE]
> **Les semestres couvrent les mêmes dates que les trimestres** (par convention, sur un même calendrier scolaire) :
> - Semestre 1 = même durée que Trim 1 + Trim 2 (Sept → Mars)
> - Semestre 2 = même durée que Trim 3 (Avr → Juil)

## Open Questions

> [!IMPORTANT]
> **Dates des semestres** : Dois-je aligner S1 = T1+T2 et S2 = T3, ou préférez-vous que les semestres aient des dates totalement libres saisies manuellement ?  
> Par défaut, je propose : **génération automatique avec S1 = début année → fin T2, S2 = début T3 → fin année**.

---

## Proposed Changes

### Composant 1 — Base de données (Backend)

---

#### [NEW] `2026_05_30_000000_add_type_and_cycle_to_terms_table.php`

Ajoute deux colonnes à `terms` :
- `term_type` ENUM `['trimestre', 'semestre']` DEFAULT `'trimestre'`
- `applicable_cycle` ENUM `['primaire', 'secondaire']` DEFAULT `'primaire'`

Met à jour les lignes existantes :
- Tous les terms actuels → `term_type='trimestre'`, `applicable_cycle='primaire'`

---

### Composant 2 — Backend Models & Services

---

#### [MODIFY] [Term.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Models/Term.php)

- Ajouter les constantes `TYPE_TRIMESTRE`, `TYPE_SEMESTRE`, `CYCLE_PRIMAIRE`, `CYCLE_SECONDAIRE`
- Ajouter `term_type` et `applicable_cycle` dans `fillable`
- Ajouter `isTrimestre()` et `isSemestre()` helpers

#### [MODIFY] [Period.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Models/Period.php)

- Mettre à jour `positionsForTerm()` pour prendre en compte le `term_type` :
  - Trimestre (3 terms) → positions globales 1..6
  - Semestre (2 terms) → positions globales 1..4

#### [NEW] `TermGenerationService.php`

Nouveau service `App\Services\TermGenerationService` :

```php
// generateForYear(SchoolYear $year): void
// - Crée 3 trimestres + 6 périodes (primaire)
// - Crée 2 semestres + 4 périodes (secondaire)
// - Dates calculées automatiquement depuis starts_on/ends_on de l'année
```

Logique des dates :
```
T1 : starts_on → T1_end (~Dec)     P1 + P2
T2 : T2_start → T2_end (~Mar)      P3 + P4
T3 : T3_start → ends_on            P5 + P6
S1 : starts_on → S1_end (=T2_end)  P1 + P2
S2 : S2_start (=T3_start) → ends_on P3 + P4
```

#### [MODIFY] [SchoolClassGenerationService.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Services/SchoolClassGenerationService.php)

- Aucun changement fonctionnel, mais injection de `TermGenerationService` possible si on centralise.

---

### Composant 3 — Contrôleurs Backend

---

#### [MODIFY] [SchoolYearController.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Http/Controllers/Api/V1/SchoolYearController.php)

Dans `store()` : appeler `TermGenerationService::generateForYear($year)` **avant** la génération des classes.

```php
public function store(SchoolYearRequest $request, ..., TermGenerationService $termGeneration): JsonResponse
{
    $year = DB::transaction(function () use (...) {
        $year = SchoolYear::query()->create(...);
        $termGeneration->generateForYear($year);   // 🆕 génération auto terms+periods
        $classes = $schoolClassGeneration->generateBaseClasses($year);
        // ...
    });
}
```

#### [MODIFY] [PeriodController.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Http/Controllers/Api/V1/PeriodController.php)

- `assertPeriodCapacity()` : message dynamique selon `term.term_type` (trimestre/semestre)
- `assertWithinTermBounds()` : idem
- Supprimer la mention hardcodée `'Un trimestre ne peut contenir que deux périodes.'`

#### [MODIFY] [TermController.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/app/Http/Controllers/Api/V1/TermController.php)

- `close()` : message dynamique `'Trimestre déjà clôturé.'` → `'Trimestre/Semestre déjà clôturé.'`
- Pareil pour les messages d'erreur

---

### Composant 4 — Seeder

---

#### [MODIFY] [DevSeeder.php](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/backend/database/seeders/DevSeeder.php)

Remplacer la création manuelle des 3 trimestres par l'appel au `TermGenerationService` :

```php
// Avant : création manuelle de $t1, $t2, $t3
// Après :
$termGeneration->generateForYear($year);
// Récupérer les terms générés pour les évals
$t1 = Term::where('school_year_id', $year->id)
           ->where('applicable_cycle', 'primaire')
           ->where('position', 1)->first();
// ...
```

Ajouter aussi la création d'évaluations de test pour le **secondaire** (liées aux semestres).

---

### Composant 5 — Ressources / Resources API

---

#### [MODIFY] `TermResource.php` (si existant)

Exposer `term_type` et `applicable_cycle` dans la réponse JSON.

#### [MODIFY] `SchoolYearResource.php` (si existant)

Pas de changement structurel — `terms.periods` est déjà inclus.

---

### Composant 6 — Frontend TypeScript

---

#### [MODIFY] [types/index.ts](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/frontend/src/types/index.ts)

```typescript
export type TermType = 'trimestre' | 'semestre'
export type TermCycle = 'primaire' | 'secondaire'

export interface Term {
  // ...champs existants
  term_type?: TermType      // 🆕
  applicable_cycle?: TermCycle  // 🆕
}
```

---

### Composant 7 — Frontend Views

---

#### [MODIFY] [SchoolYearDetailView.vue](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/frontend/src/views/SchoolYearDetailView.vue)

- **Tri des terms** : séparer en deux groupes (primaire / secondaire)
- **Labels dynamiques** : `termLabel(term)` → `'Trimestre 1'` ou `'Semestre 1'`
- **Confirmation de suppression** : `'Supprimer un trimestre'` → `termTypeLabel(term)` dynamique
- **Badge** cycle sur chaque term card : `Primaire` / `Secondaire`
- **Compteur hero** : `'Trimestres clôturés'` → `'Termes clôturés'` (ou deux compteurs séparés)

#### [MODIFY] [EvaluationsView.vue](file:///home/jeff-bm/Bureau/JEFF_TFC_APP/frontend/src/views/EvaluationsView.vue)

- Filtre `'Tous les trimestres'` → `'Tous les termes'`
- Label du select affiche `'Trimestre 1 (Primaire)'` ou `'Semestre 1 (Secondaire)'`
- Formulaire de création : label dynamique selon `term.term_type`

#### Autres views impactées (mises à jour de labels)
- `ReportCardView.vue` — affichage du nom du term
- `StudentBulletinView.vue` — entête bulletin "Trimestre / Semestre"
- `SchoolYearClassDetailView.vue` — mentions de trimestre

---

## Verification Plan

### Automatisé
```bash
# Backend : migrate + seed
cd backend
php artisan migrate
php artisan db:seed --class=DevSeeder

# Vérifier les terms créés
php artisan tinker --execute="
  \$y = App\Models\SchoolYear::first();
  echo \$y->terms()->count() . ' terms créés\n';
  \$y->terms()->each(fn(\$t) => print(\$t->name.' ('.\$t->term_type.', '.\$t->applicable_cycle.')\n'));
"
```

### Manuel
1. Créer une nouvelle année scolaire → vérifier que 5 terms (3 trimestres + 2 semestres) + 10 périodes sont créés automatiquement
2. Ouvrir `SchoolYearDetailView` → les deux groupes doivent s'afficher séparément avec les bons labels
3. Créer une évaluation pour une classe primaire → seuls les trimestres disponibles
4. Créer une évaluation pour une classe secondaire → seuls les semestres disponibles
5. Clôturer un semestre → message "Semestre clôturé" (pas "Trimestre")
