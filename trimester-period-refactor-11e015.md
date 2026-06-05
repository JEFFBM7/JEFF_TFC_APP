# Refactor « Semestres » → « Trimestres » + introduction des Périodes (RDC primaire/secondaire)

Renommer toute la chaîne `semesters` → `terms` (DB, modèles, controllers, services, routes, tests, types TS, vues), introduire une nouvelle entité `Period` (2 par trimestre, 6/an) rattachant les évaluations, sans modifier la fréquence des bulletins (3×/an, à la clôture du trimestre).

---

## 1. Décisions validées

| # | Décision | Choix retenu |
|---|----------|--------------|
| 1 | Nommage code/DB | **`terms` / `Term`** (standard EN, libellé UI = « Trimestre ») |
| 2 | Modélisation des périodes | **Nouvelle entité `Period`** (table dédiée, FK `term_id`) |
| 3 | Bulletins | **3×/an seulement** — clôture trimestre uniquement |
| 4 | Données existantes | **Reset DB + re-seed** (env dev, pas de backfill) |

---

## 2. Vue d'ensemble du modèle cible

```
SchoolYear (1) ─── (3) Term ─── (2) Period ─── (n) Evaluation ─── (n) Grade
                              ↑
                      bulletin trimestriel
                      (clôture du Term)
```

- Une **année scolaire** contient 3 **trimestres**.
- Chaque **trimestre** contient 2 **périodes** (P1, P2).
- Chaque **évaluation** est rattachée à *une* période ET hérite implicitement de son trimestre via `period.term_id`. On garde `term_id` dénormalisé sur `evaluations` pour les requêtes rapides (rankings, bulletins).
- Le **bulletin** continue d'être calculé à la maille `Term` (agrège les notes des 2 périodes).

---

## 3. Backend — Renommage `semesters` → `terms`

### 3.1 Migration de renommage

**Nouveau fichier** `2026_05_21_000000_rename_semesters_to_terms.php` :
- Drop FKs : `evaluations.semester_id`, `teacher_assignments.semester_id`, `report_card_appreciations.semester_id`.
- `Schema::rename('semesters', 'terms')`.
- Rename columns `semester_id` → `term_id` sur les 3 tables.
- Recréer les FKs vers `terms.id`.
- Recréer index composite `(classroom_id, subject_id, term_id)` sur `evaluations`.
- Recréer unique `(student_id, term_id)` sur `report_card_appreciations`.
- `down()` symétrique.

> **Note** : la migration `2026_05_20_000000_rename_terms_to_semesters.php` reste en place (historique). Le reset `migrate:fresh` exécutera les deux dans l'ordre, le résultat final = `terms`.

### 3.2 Renommage des classes PHP

| Avant | Après |
|---|---|
| `app/Models/Semester.php` | `app/Models/Term.php` |
| `app/Http/Controllers/Api/V1/SemesterController.php` | `TermController.php` |
| `app/Http/Resources/Api/V1/SemesterResource.php` | `TermResource.php` |
| `app/Http/Requests/Api/V1/SemesterRequest.php` | `TermRequest.php` |
| `app/Services/SemesterClosureService.php` | `TermClosureService.php` |
| `database/factories/SemesterFactory.php` | `TermFactory.php` |
| `tests/Feature/Api/V1/TermTest.php` | inchangé (déjà bien nommé !) — adapter le contenu |

### 3.3 Renommage de symboles dans tous les fichiers PHP

`grep` confirme **53 fichiers backend** touchés. Search/replace ciblés :

| Pattern | Remplacement |
|---|---|
| `Semester::class` | `Term::class` |
| `use App\Models\Semester` | `use App\Models\Term` |
| `Semester $semester` | `Term $term` |
| `$semester->` | `$term->` |
| `semester_id` | `term_id` |
| `'semester_id'` (validation, fillable) | `'term_id'` |
| `->semester()` (relations) | `->term()` |
| `semesters()` (relations hasMany) | `terms()` |
| `closed_semesters` (clé stats) | `closed_terms` |
| `closeSemester` / `getSemester` | `closeTerm` / `getTerm` |

Fichiers principaux à toucher :
- `app/Models/{SchoolYear,Term,Evaluation,ReportCardAppreciation,TeacherAssignment}.php`
- `app/Services/{TermClosureService,ReportCardService,LowGradeAlertService,StudentTimelineService}.php`
- `app/Support/SchoolYearContext.php`
- `app/Http/Controllers/Api/V1/{TermController,EvaluationController,ReportCardController,SchoolYearController,ReportsController,DashboardController,ParentPortalController,StudentPortalController,StudentsAtRiskController,SubjectController,ClassRoomController,TeacherController,AppreciationController}.php`
- `app/Http/Requests/Api/V1/{TermRequest,EvaluationRequest,AssignmentRequest,SubjectRequest}.php`
- `app/Http/Resources/Api/V1/{TermResource,AssignmentResource,SubjectResource}.php`
- `app/Notifications/{ReportCardPublishedNotification,LowAverageNotification}.php`
- `database/factories/{TermFactory,EvaluationFactory}.php`
- `database/seeders/DevSeeder.php`
- `resources/views/report_cards/pdf.blade.php`
- `routes/api.php`
- Tous les fichiers de `tests/Feature/Api/V1/` qui mentionnent `Semester` (~12 fichiers).

### 3.4 Routes

Dans `routes/api.php` (15 occurrences ~) :
- `Route::apiResource('semesters', …)` → `Route::apiResource('terms', TermController::class)`.
- `semesters/{semester}/close` → `terms/{term}/close`.
- `students/{student}/report-cards/{semester}` → `students/{student}/report-cards/{term}`.
- `classrooms/{classroom}/ranking/{semester}` → `classrooms/{classroom}/ranking/{term}`.
- `students/{student}/appreciations/{semester}` → `students/{student}/appreciations/{term}`.
- `student/semesters` → `student/terms` (portail élève).
- `student/report-card/{semester}` → `student/report-card/{term}`.
- `parent/semesters` → `parent/terms`.
- `parent/children/{student}/report-card/{semester}` → idem `{term}`.
- Routes rapports CSV : `ranking/{semester}/csv` → `ranking/{term}/csv`.

> **Pas de double versionning** (l'API n'est pas encore en prod). Si le besoin émerge, on pourra ajouter des alias plus tard.

---

## 4. Backend — Nouvelle entité `Period`

### 4.1 Migration

**Nouveau fichier** `2026_05_21_000100_create_periods_table.php` :
```php
Schema::create('periods', function (Blueprint $t) {
    $t->id();
    $t->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
    $t->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();
    $t->string('name', 64);                    // « Période 1 » … « Période 6 »
    $t->unsignedTinyInteger('position');       // position annuelle : 1 à 6
    $t->date('starts_on');
    $t->date('ends_on');
    $t->timestamp('closed_at')->nullable();    // optionnel : verrouille la saisie de notes
    $t->timestamps();
    $t->unique(['term_id', 'position']);
    $t->unique(['term_id', 'name']);
});
```

**Nouveau fichier** `2026_05_21_000200_add_period_id_to_evaluations.php` :
```php
Schema::table('evaluations', function (Blueprint $t) {
    $t->foreignId('period_id')
        ->nullable()                            // nullable transitoire ; le seeder fixera la valeur
        ->after('term_id')
        ->constrained('periods')
        ->nullOnDelete();
    $t->index(['classroom_id', 'subject_id', 'term_id', 'period_id']);
});
```

> **Choix conscient** : on **garde `term_id` sur `evaluations`** (dénormalisé) pour ne pas casser les requêtes existantes (`ReportCardService::compute`, ranking, dashboard) qui filtrent par trimestre. Cohérence applicative garantie par une règle de validation : `period.term_id == evaluation.term_id`.

### 4.2 Modèle `Period`

`app/Models/Period.php` :
- `Fillable`: `term_id`, `name`, `position`, `starts_on`, `ends_on`, `closed_at`.
- `casts`: dates + `closed_at: datetime`.
- `belongsTo(Term::class)`.
- `hasMany(Evaluation::class)`.
- Méthodes : `isClosed()`, scope `current()` (date du jour entre `starts_on` et `ends_on`).

### 4.3 Mise à jour modèle `Evaluation`

- Ajouter `period_id` aux `Fillable`.
- Relation `belongsTo(Period::class)`.
- Validation FormRequest : `period_id` requis, et `Period::find($id)->term_id === $this->term_id` (rule custom).

### 4.4 Controller `PeriodController` (admin)

`app/Http/Controllers/Api/V1/PeriodController.php` — CRUD standard :
- `index(Request)` — filtré par `?term_id=` ou `?school_year_id=`.
- `store(PeriodRequest)` — vérifie année non archivée, `position ∈ {1,2}`, max 2 par trimestre.
- `show(Period)`.
- `update(PeriodRequest, Period)` — refus si trimestre clos.
- `destroy(Period)` — refus si évaluations rattachées.
- `close(Period)` (optionnel) — verrouille la saisie de notes pour cette période.

### 4.5 FormRequest `PeriodRequest`

```php
'term_id'    => ['required', 'exists:terms,id'],
'name'       => ['required', 'string', 'max:64'],
'position'   => ['required', 'integer', 'in:1,2'],
'starts_on'  => ['required', 'date'],
'ends_on'    => ['required', 'date', 'after_or_equal:starts_on'],
```

### 4.6 Resource `PeriodResource`

Champs : `id`, `term_id`, `name`, `position`, `starts_on`, `ends_on`, `closed_at`, `is_closed`.

### 4.7 Routes (ajouts)

```php
Route::middleware(['auth:sanctum','role:admin'])->group(function () {
    Route::apiResource('periods', PeriodController::class);
    Route::post('periods/{period}/close', [PeriodController::class, 'close']);
});
// Lecture pour enseignants/secrétariat (pour saisir/filtrer les évaluations)
Route::middleware(['auth:sanctum','role:admin,enseignant,secretariat'])->group(function () {
    Route::get('periods', [PeriodController::class, 'index']);
});
```

### 4.8 Adaptations services/controllers existants

- **`EvaluationController::store`** : exige `period_id`, dérive `term_id` depuis `Period::find($period_id)->term_id`.
- **`EvaluationController::index`** : ajoute filtre `?period_id=`.
- **`ReportCardService::compute`** : inchangé (continue d'agréger par `term_id`). Ajout d'une méthode optionnelle `computePeriodAverage(Student, Period)` pour l'UI (suivi continu).
- **`StudentTimelineService`** : ajoute un breakdown `period_averages` à côté de `term_averages` (utile pour les charts).
- **`SchoolYearController`** stats : ajouter `periods` et `closed_periods` dans `summary`.

### 4.9 Seeder `DevSeeder`

Pour chaque trimestre créé, insérer 2 périodes selon la numérotation annuelle :

```php
$t1 = Term::updateOrCreate([...], [...]);
Period::updateOrCreate(['term_id' => $t1->id, 'position' => 1],
    ['name' => 'Période 1', 'starts_on' => '2025-09-01', 'ends_on' => '2025-10-30']);
Period::updateOrCreate(['term_id' => $t1->id, 'position' => 2],
    ['name' => 'Période 2', 'starts_on' => '2025-11-01', 'ends_on' => '2025-12-20']);
// idem pour $t2 et $t3
// $t2 => Période 3/4 ; $t3 => Période 5/6
```

Et adapter les `Evaluation::updateOrCreate(...)` du seeder pour fixer `period_id` (déduit de `held_on`).

### 4.10 Tests

| Fichier | Action |
|---|---|
| `tests/Feature/Api/V1/TermTest.php` | Adapter (rename `Semester` → `Term`, routes `terms/`) |
| `tests/Feature/Api/V1/PeriodTest.php` (**nouveau**) | CRUD période, `position ∈ {1,2}`, max 2/trimestre, FK validation, bulletins inchangés |
| `tests/Feature/Api/V1/EvaluationGradeTest.php` | Ajouter `period_id` dans payloads, vérifier validation cross-FK |
| `tests/Feature/Api/V1/ReportCardTest.php` | Vérifier que le bulletin agrège bien les notes des 2 périodes du trimestre |
| `tests/Feature/Api/V1/StudentTimelineTest.php` | Vérifier nouveau breakdown `period_averages` |
| Tous les autres tests (~12) | Search/replace `Semester` → `Term`, `semester_id` → `term_id` |

---

## 5. Frontend — Renommage et adaptations

### 5.1 Types TS (`frontend/src/types/index.ts`)

| Avant | Après |
|---|---|
| `interface Semester` | `interface Term` |
| `semesters?: Semester[]` (sur `SchoolYear`) | `terms?: Term[]` |
| `SchoolYearStats.semesters / closed_semesters` | `terms / closed_terms` |
| `SchoolYearHistoryTerm` (déjà bien nommé !) | inchangé, mais `SchoolYearHistory.semesters` → `terms` |
| **Nouveau** `interface Period` | `{ id, term_id, name, position: 1\|2, starts_on, ends_on, closed_at?, is_closed? }` |
| Étendre `Evaluation` | ajouter `period_id: number` |

### 5.2 Vues à modifier (16 fichiers)

| Fichier | Changements |
|---|---|
| `views/SchoolYearDetailView.vue` | Liste `terms` au lieu de `semesters` ; nouveau bloc « Périodes » imbriqué sous chaque trimestre ; CRUD période. |
| `views/SchoolYearsView.vue` | Renommer compteurs/stats. |
| `views/EvaluationsView.vue` | Ajouter sélecteur de **Période** (cascade : Trimestre → Période). Le `term_id` reste pour le filtrage rapide. |
| `views/SubjectsView.vue` | Renommage uniquement. |
| `views/DashboardView.vue` (admin/enseignant) | Renommer clés stats `closed_semesters` → `closed_terms`. Pas de chart par période en v1 (option v2). |
| `views/ReportCardView.vue`, `StudentBulletinView.vue`, `ParentChildView.vue` | Renommage `semester` → `term` dans templates et appels API. Pas de bulletin par période. |
| `views/StudentDashboardView.vue`, `ParentDashboardView.vue` | Renommage. |
| `views/StudentDetailView.vue`, `views/TeacherDetailView.vue`, `views/StudentsAtRiskView.vue`, `views/ReportsView.vue`, `views/SchoolYearClassDetailView.vue` | Renommage. |

### 5.3 Nouvelle vue / composant `PeriodFormModal.vue`

- Modal d'ajout/édition des 2 périodes d'un trimestre, intégrée dans `SchoolYearDetailView`.
- Champs : nom, position annuelle (P1/P2, P3/P4 ou P5/P6 selon le trimestre), `starts_on`, `ends_on`.
- Validation cliente : la période doit être incluse dans les bornes du trimestre parent.

### 5.4 Routes Vue Router

- `/school-years/:id` : pas de changement de URL.
- Aucune nouvelle route top-level (les périodes vivent à l'intérieur de la fiche année).

### 5.5 Sidebar / libellés UI

Aucune sidebar à toucher (les trimestres ne sont pas une entrée principale). Remplacer toutes les chaînes `« Semestre »` → `« Trimestre »` côté UI (déjà partiellement fait).

---

## 6. Documentation

| Fichier | Changements |
|---|---|
| `CDC_MALUNGA.md` | Glossaire : préciser « Trimestre = période de 3 mois, contient 2 sous-périodes ». §4.6 : préciser 3 bulletins/an. §4.9 : ajouter « configuration des périodes ». |
| `PRD.MD` | Idem ; corriger les mentions résiduelles « semestre ». |
| `GUIDE_MAP.md` | Renommer chemins API. |
| `db_auth.md` | RAS. |

> **Hors périmètre** : aucune modification de la doc Swagger (régénérée automatiquement par Scramble).

---

## 7. Ordre d'implémentation (séquentiel, 1 commit par étape)

1. **Backend — renommage `semesters` → `terms`** (migration + classes + symboles + routes + tests verts).  
   *Commit : `refactor(backend): rename semesters to terms (Trimestre domain alignment)`*

2. **Frontend — renommage `Semester` → `Term`** (types + vues + composants).  
   *Commit : `refactor(frontend): rename Semester to Term across UI and types`*

3. **Backend — entité `Period`** (migration + modèle + controller + request + resource + routes + adaptation `EvaluationController` + seeder + tests).  
   *Commit : `feat(backend): introduce Period entity (2 sub-periods per Term)`*

4. **Frontend — UI Période** (types `Period`, modal, intégration dans `SchoolYearDetailView` et `EvaluationsView`).  
   *Commit : `feat(frontend): periods management and evaluation period selector`*

5. **Backend — timeline étendu** (StudentTimelineService renvoie aussi `period_averages`).  
   *Commit : `feat(backend): expose per-period averages in student timeline`*

6. **Documentation** (CDC + PRD).  
   *Commit : `docs: align with trimester+period model (RDC primaire/secondaire)`*

> Après l'étape 1 et avant l'étape 3 : **`php artisan migrate:fresh --seed`** pour repartir d'une base propre.

---

## 8. Risques & points d'attention

- **Cohérence `term_id` ↔ `period.term_id` sur `evaluations`** : règle de validation custom obligatoire (sinon possibilité d'incohérence). Test dédié.
- **Volume de fichiers touchés** par le renommage : ~70 fichiers (53 backend + 16 frontend). Tester intégralement après chaque étape (`php artisan test`, `npm run build`, `npm run typecheck`).
- **Bulletins déjà clos en dev** : remis à zéro par `migrate:fresh`. Annoncer à l'utilisateur.
- **Bundle / cache navigateur** : forcer un refresh après le rename (les anciennes clés persistées `localStorage` éventuelles avec `semester_id` doivent être invalidées — à vérifier dans `useSchoolYearStore` ou équivalent).
- **API publique** : aucune (l'API n'est pas encore consommée par un tiers). Pas besoin d'alias de compatibilité.
- **Performance bulletin** : conserver l'index composite mis à jour `(classroom_id, subject_id, term_id, period_id)`. Vérifier `EXPLAIN` sur les 3 requêtes critiques (`ReportCardService::compute`, `classRanking`, `StudentTimelineService`).

---

## 9. Livrables

### Backend
- 2 nouvelles migrations (`rename_semesters_to_terms`, `create_periods`, `add_period_id_to_evaluations`).
- 6 fichiers renommés (`Semester*` → `Term*` + factory).
- 4 nouveaux fichiers (`Period.php`, `PeriodController.php`, `PeriodRequest.php`, `PeriodResource.php`, `PeriodFactory.php`, `PeriodTest.php`).
- ~50 fichiers modifiés (renommage de symboles).
- `DevSeeder` adapté (6 périodes + `period_id` sur évaluations).
- ~12 tests adaptés + 1 nouveau test feature.

### Frontend
- ~17 fichiers Vue/TS modifiés (renommage).
- 1 nouveau composant `PeriodFormModal.vue`.
- 1 nouveau type `Period` dans `types/index.ts`.

### Documentation
- 3 fichiers `.md` mis à jour (`CDC_MALUNGA`, `PRD`, `GUIDE_MAP`).
