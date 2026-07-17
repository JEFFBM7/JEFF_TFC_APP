<?php

namespace App\Console\Commands;

use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installe le programme de cours officiel (RDC) sur les classes de l'année
 * scolaire courante : crée les cours puis les rattache aux classes du bon
 * cycle / de la bonne option, avec le coefficient prévu par le programme.
 *
 *   php artisan subjects:seed-program                      # simulation
 *   php artisan subjects:seed-program --force              # écrit en base
 *   php artisan subjects:seed-program --cycles=primaire,cteb --force
 *
 * - `subjects.name` étant unique, un cours porté par plusieurs cycles (ex.
 *   Mathématiques en primaire ET en 7e/8e) est un SEUL cours rattaché aux deux,
 *   avec le coefficient propre à chaque classe (porté par la table pivot).
 * - Idempotent : les cours sont créés/mis à jour par nom, le rattachement par
 *   (classe, cours). Relancer ne duplique rien.
 * - « Appréciation qualitative » (maternelle) n'existant pas dans l'appli,
 *   ces cours prennent le type d'évaluation par défaut (sur_20).
 */
class SeedCourseProgram extends Command
{
    protected $signature = 'subjects:seed-program
        {--cycles= : Limiter à certains cycles (ex. primaire,cteb). Défaut : tous}
        {--force : Écrit réellement en base (sinon simulation)}';

    protected $description = "Installe le programme de cours officiel (RDC) sur les classes de l'année courante.";

    /** Correspondance section du programme -> cycle + options ciblées. */
    private const SECTION_TARGETS = [
        "Maternelle (Classes d'accueil à 3ème)" => [Level::CYCLE_MATERNEL, []],
        'Primaire (1ère à 6ème)' => [Level::CYCLE_PRIMAIRE, []],
        'Éducation de Base (7ème et 8ème)' => [Level::CYCLE_CTEB, []],
        'Toutes les Humanités' => [Level::CYCLE_SECONDAIRE, []],
        'Humanités Scientifiques' => [Level::CYCLE_SECONDAIRE, ['Scientifique']],
        'Latin-Philosophie (Littéraire)' => [Level::CYCLE_SECONDAIRE, ['Latin-Philosophie', 'Littéraire']],
        'Humanités Pédagogiques' => [Level::CYCLE_SECONDAIRE, ['Pédagogique générale']],
        'Commerciale et Gestion' => [Level::CYCLE_SECONDAIRE, ['Commerciale & Gestion']],
        'Techniques Industrielles' => [Level::CYCLE_SECONDAIRE, [
            'Mécanique', 'Électricité', 'Électronique', 'Pétrochimie', 'Construction',
        ]],
        'Coupe et Couture (Techniques Sociales)' => [Level::CYCLE_SECONDAIRE, ['Coupe et couture']],
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $cycleFilter = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('cycles')),
        )));

        $year = SchoolYear::query()->where('is_current', true)->first();
        if ($year === null) {
            $this->error('Aucune année scolaire courante définie.');

            return self::FAILURE;
        }

        $classrooms = ClassRoom::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('school_year_id', $year->id))
            ->with(['level', 'schoolOption'])
            ->get();

        if ($classrooms->isEmpty()) {
            $this->error("Aucune classe pour l'année {$year->name}.");

            return self::FAILURE;
        }

        $optionIdsByName = SchoolOption::query()->pluck('id', 'name');

        // Résout, pour chaque ligne du programme, les classes visées puis
        // regroupe par cours (le nom est unique côté base).
        $plan = [];   // nom du cours => ['row' => ligne, 'targets' => [classroomId => coef]]
        $skipped = [];
        foreach (self::program() as $row) {
            [$name, $code, $coefficient, $description, $section, $evaluationType] = $row;

            $target = self::SECTION_TARGETS[$section] ?? null;
            if ($target === null) {
                $skipped[] = "{$name} — section inconnue : {$section}";

                continue;
            }
            [$cycle, $optionNames] = $target;

            if ($cycleFilter !== [] && ! in_array($cycle, $cycleFilter, true)) {
                continue;
            }

            $optionIds = [];
            foreach ($optionNames as $optionName) {
                if (isset($optionIdsByName[$optionName])) {
                    $optionIds[] = $optionIdsByName[$optionName];
                }
            }

            $matching = $classrooms->filter(function (ClassRoom $classroom) use ($cycle, $optionNames, $optionIds): bool {
                if ($classroom->level?->cycle !== $cycle) {
                    return false;
                }
                if ($optionNames === []) {
                    return true; // tout le cycle (tronc commun)
                }

                return $classroom->school_option_id !== null
                    && in_array($classroom->school_option_id, $optionIds, true);
            });

            if ($matching->isEmpty()) {
                continue; // aucune classe pour cette section dans cette année
            }

            $plan[$name] ??= ['row' => $row, 'targets' => []];
            foreach ($matching as $classroom) {
                $plan[$name]['targets'][$classroom->id] = (float) $coefficient;
            }
        }

        if ($plan === []) {
            $this->warn('Aucun cours à installer (aucune classe correspondante).');

            return self::SUCCESS;
        }

        $attachments = array_sum(array_map(fn ($e) => count($e['targets']), $plan));
        $this->info(($write ? 'INSTALLATION' : 'SIMULATION (dry-run)')." — année {$year->name}");
        $this->line(count($plan)." cours · {$attachments} rattachements classe/cours.");

        // Récapitulatif par cycle.
        $perCycle = [];
        foreach ($plan as $entry) {
            foreach (array_keys($entry['targets']) as $classroomId) {
                $cycle = $classrooms->firstWhere('id', $classroomId)?->level?->cycle ?? '?';
                $perCycle[$cycle] = ($perCycle[$cycle] ?? 0) + 1;
            }
        }
        $this->table(
            ['Cycle', 'Rattachements'],
            collect($perCycle)->map(fn ($n, $c) => [$c, $n])->values()->all(),
        );

        foreach ($skipped as $line) {
            $this->warn($line);
        }

        if (! $write) {
            $this->warn('Simulation uniquement — aucune écriture. Relance avec --force pour installer.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $linked = 0;
        DB::transaction(function () use ($plan, &$created, &$updated, &$linked): void {
            foreach ($plan as $name => $entry) {
                [, $code, $coefficient, $description, , $evaluationType] = $entry['row'];

                $subject = Subject::query()->where('name', $name)->first();

                // `code` est unique : ne le pose que s'il est libre (ou déjà le nôtre).
                $codeTaken = $code !== '' && Subject::query()
                    ->where('code', $code)
                    ->when($subject !== null, fn ($q) => $q->whereKeyNot($subject->id))
                    ->exists();

                $payload = [
                    'description' => $description !== '' ? $description : null,
                    'default_coefficient' => (float) $coefficient,
                    'evaluation_type' => $evaluationType,
                    'status' => 'actif',
                ];
                if (! $codeTaken && $code !== '') {
                    $payload['code'] = $code;
                }

                if ($subject === null) {
                    $subject = Subject::query()->create(['name' => $name] + $payload);
                    $created++;
                } else {
                    $subject->fill($payload)->save();
                    $updated++;
                }

                foreach ($entry['targets'] as $classroomId => $coef) {
                    $subject->classrooms()->syncWithoutDetaching([
                        $classroomId => ['coefficient' => $coef],
                    ]);
                    $subject->classrooms()->updateExistingPivot($classroomId, ['coefficient' => $coef]);
                    $linked++;
                }
            }
        });

        $this->info("Terminé : {$created} cours créé(s), {$updated} mis à jour, {$linked} rattachement(s).");

        return self::SUCCESS;
    }

    /**
     * Programme officiel RDC : [nom, code, coefficient, description, section,
     * type d'évaluation].
     *
     * @return list<array{0:string,1:string,2:float|int,3:string,4:string,5:string}>
     */
    private static function program(): array
    {
        return [
            ['Langage et Communication', 'LAN-MAT', 1, 'Expression orale et pré-lecture', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Pré-mathématiques', 'PMA-MAT', 1, 'Éveil logico-mathématique et pré-calcul', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Éveil scientifique et Découverte du milieu', 'EVE-MAT', 1, 'Observation de l\'environnement proche', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Psychomotricité et Éducation sensorielle', 'PSY-MAT', 1, 'Motricité globale et fine, éveil sensoriel', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Éducation artistique', 'ART-MAT', 1, 'Chant, dessin et jeux éducatifs', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Éducation religieuse et morale', 'REL-MAT', 1, 'Valeurs, savoir-vivre et prière', 'Maternelle (Classes d\'accueil à 3ème)', 'sur_20'],
            ['Mathématiques', 'MAT-PRI', 2, 'Calcul et numération', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Français', 'FRA-PRI', 2, 'Lecture et écriture', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Étude du Milieu', 'EDM-PRI', 1, 'Découverte du monde et sciences', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Éducation Morale et Civique', 'EMC-PRI', 1, 'Savoir-vivre et civisme', 'Primaire (1ère à 6ème)', 'sur_10'],
            ['Éveil Scientifique', 'EVS-PRI', 1, 'Observation et expériences simples', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Anglais', 'ANG-PRI', 1, 'Initiation à l\'anglais (degré terminal)', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Éducation Physique et Sportive', 'EPS-PRI', 1, 'Activités physiques et jeux', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Éducation Esthétique et Artistique', 'EST-PRI', 1, 'Dessin, chant et travaux manuels', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Éducation Religieuse', 'REL-PRI', 1, 'Éducation à la foi et aux valeurs', 'Primaire (1ère à 6ème)', 'sur_10'],
            ['Langue Nationale (Swahili)', 'SWA-PRI', 1, 'Expression en langue nationale du milieu', 'Primaire (1ère à 6ème)', 'sur_20'],
            ['Mathématiques', 'MAT-EB', 3, 'Algèbre et géométrie de base', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Français', 'FRA-EB', 3, 'Grammaire et analyse', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Anglais', 'ANG-EB', 2, 'Initiation à la langue anglaise', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Sciences de la Vie et de la Terre (SVT)', 'SVT-EB', 2, 'Biologie et environnement', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Technologie / Informatique', 'TEC-EB', 1, 'Initiation aux TIC', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Histoire', 'HIS-EB', 2, 'Histoire générale et de la RDC', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Géographie', 'GEO-EB', 2, 'Géographie physique et humaine', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Éducation Civique et Morale', 'ECM-EB', 1, 'Citoyenneté et valeurs républicaines', 'Éducation de Base (7ème et 8ème)', 'sur_10'],
            ['Éducation Physique et Sportive', 'EPS-EB', 1, 'Activités physiques et sportives', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Éducation Artistique', 'ART-EB', 1, 'Dessin, musique et arts', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Éducation Religieuse', 'REL-EB', 1, 'Éducation à la foi et à l\'éthique', 'Éducation de Base (7ème et 8ème)', 'sur_10'],
            ['Langue Nationale (Swahili)', 'SWA-EB', 1, 'Communication en langue nationale', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Physique et Chimie', 'PHC-EB', 2, 'Notions de physique et de chimie', 'Éducation de Base (7ème et 8ème)', 'sur_20'],
            ['Français (Tronc Commun)', 'FRA-HUM', 3, 'Littérature et dissertation', 'Toutes les Humanités', 'sur_20'],
            ['Éducation à la Citoyenneté (ECM)', 'ECM-HUM', 1, 'Institutions de la République', 'Toutes les Humanités', 'sur_10'],
            ['Anglais (Tronc Commun)', 'ANG-HUM', 2, 'Langue anglaise : compréhension et expression', 'Toutes les Humanités', 'sur_20'],
            ['Éducation Physique et Sportive', 'EPS-HUM', 1, 'Activités physiques et sportives', 'Toutes les Humanités', 'sur_20'],
            ['Éducation Religieuse', 'REL-HUM', 1, 'Éthique et éducation à la vie', 'Toutes les Humanités', 'sur_10'],
            ['Informatique / TIC', 'INF-HUM', 2, 'Initiation à l\'informatique et aux TIC', 'Toutes les Humanités', 'sur_20'],
            ['Mathématiques Approfondies', 'MAT-SCI', 5, 'Algèbre complexe et analyse', 'Humanités Scientifiques', 'sur_20'],
            ['Physique', 'PHY-SCI', 4, 'Mécanique et électricité', 'Humanités Scientifiques', 'sur_20'],
            ['Chimie', 'CHI-SCI', 4, 'Chimie minérale et organique', 'Humanités Scientifiques', 'sur_20'],
            ['Biologie', 'BIO-SCI', 3, 'Anatomie et génétique', 'Humanités Scientifiques', 'sur_20'],
            ['Latin', 'LAT-LIT', 4, 'Langue et textes latins', 'Latin-Philosophie (Littéraire)', 'sur_20'],
            ['Philosophie et Morale', 'PHI-LIT', 3, 'Introduction à la philosophie et à l\'éthique', 'Latin-Philosophie (Littéraire)', 'sur_20'],
            ['Histoire', 'HIS-LIT', 3, 'Histoire générale et contemporaine', 'Latin-Philosophie (Littéraire)', 'sur_20'],
            ['Géographie', 'GEO-LIT', 3, 'Géographie humaine et économique', 'Latin-Philosophie (Littéraire)', 'sur_20'],
            ['Littérature Française', 'LIT-LIT', 4, 'Analyse littéraire et dissertation', 'Latin-Philosophie (Littéraire)', 'sur_20'],
            ['Pédagogie Générale', 'PED-PED', 4, 'Principes de l\'éducation', 'Humanités Pédagogiques', 'sur_20'],
            ['Psychologie', 'PSY-PED', 3, 'Psychologie de l\'enfant et de l\'adolescent', 'Humanités Pédagogiques', 'sur_20'],
            ['Didactique des Disciplines', 'DID-PED', 4, 'Méthodologie d\'enseignement', 'Humanités Pédagogiques', 'sur_20'],
            ['Pratique Professionnelle', 'PRA-PED', 5, 'Leçons d\'essai et stage', 'Humanités Pédagogiques', 'sur_20'],
            ['Comptabilité Générale', 'CPT-COM', 5, 'Bilans et écritures comptables', 'Commerciale et Gestion', 'sur_20'],
            ['Économie Politique', 'ECO-COM', 3, 'Microéconomie et macroéconomie', 'Commerciale et Gestion', 'sur_20'],
            ['Mathématiques Financières', 'MAF-COM', 3, 'Amortissements et intérêts', 'Commerciale et Gestion', 'sur_20'],
            ['Droit Commercial', 'DRO-COM', 2, 'Droit des affaires', 'Commerciale et Gestion', 'sur_20'],
            ['Correspondance Commerciale', 'COR-COM', 2, 'Rédaction administrative', 'Commerciale et Gestion', 'sur_20'],
            ['Technologie Générale', 'TEC-IND', 4, 'Principes technologiques et matériaux', 'Techniques Industrielles', 'sur_20'],
            ['Dessin Technique et Industriel', 'DES-IND', 4, 'Dessin technique, plans et schémas', 'Techniques Industrielles', 'sur_20'],
            ['Mathématiques Appliquées', 'MAT-IND', 4, 'Mathématiques pour les techniques', 'Techniques Industrielles', 'sur_20'],
            ['Physique Appliquée', 'PHY-IND', 3, 'Physique appliquée aux techniques industrielles', 'Techniques Industrielles', 'sur_20'],
            ['Pratique Professionnelle (Ateliers)', 'PRA-IND', 5, 'Travaux d\'atelier et stages', 'Techniques Industrielles', 'sur_20'],
            ['Technologie de la Couture', 'TEC-CC', 4, 'Technologie des textiles et du matériel', 'Coupe et Couture (Techniques Sociales)', 'sur_20'],
            ['Coupe et Confection', 'COU-CC', 5, 'Patronage, coupe et confection', 'Coupe et Couture (Techniques Sociales)', 'sur_20'],
            ['Dessin de Mode et Stylisme', 'DES-CC', 3, 'Croquis de mode et création', 'Coupe et Couture (Techniques Sociales)', 'sur_20'],
            ['Économie Familiale et Puériculture', 'ECF-CC', 3, 'Gestion du foyer et soins à l\'enfant', 'Coupe et Couture (Techniques Sociales)', 'sur_20'],
            ['Pratique Professionnelle (Ateliers)', 'PRA-CC', 5, 'Travaux pratiques et réalisations', 'Coupe et Couture (Techniques Sociales)', 'sur_20'],
        ];
    }
}
