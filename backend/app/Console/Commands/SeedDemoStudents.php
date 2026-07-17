<?php

namespace App\Console\Commands;

use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Insère des élèves de démonstration dans les classes de la 1re primaire à la
 * 8e CTEB (cycles primaire + cteb) de l'année scolaire courante.
 *
 *   php artisan students:seed-demo              # simulation (dry-run)
 *   php artisan students:seed-demo --force      # écrit réellement en base
 *   php artisan students:seed-demo --per-class=20 --force
 *
 * - Répartition round-robin équilibrée entre les classes, plafonnée à
 *   --per-class élèves par classe (défaut 25).
 * - Idempotent : matricule déterministe « DEMO-{classe}-{n} », un second appel
 *   met à jour au lieu de dupliquer. Les élèves de démo sont ainsi faciles à
 *   repérer et à retirer (registration_number commençant par « DEMO- »).
 * - Dates de naissance recalculées selon le niveau (les dates du fichier sont
 *   des dates d'adultes) ; le père/la mère/le téléphone sont posés sur la fiche.
 */
class SeedDemoStudents extends Command
{
    protected $signature = 'students:seed-demo
        {--per-class=25 : Nombre maximum d\'élèves par classe}
        {--cycles=primaire,cteb : Cycles ciblés (les niveaux « 1 à 8 »)}
        {--force : Écrit réellement en base (sinon simulation)}';

    protected $description = "Insère des élèves de démonstration (max N par classe) de la 1re primaire à la 8e CTEB pour l'année courante.";

    public function handle(): int
    {
        $perClass = max(1, (int) $this->option('per-class'));
        $cycles = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('cycles')))));
        $write = (bool) $this->option('force');

        $year = SchoolYear::query()->where('is_current', true)->first();
        if ($year === null) {
            $this->error('Aucune année scolaire courante définie.');

            return self::FAILURE;
        }

        $classrooms = ClassRoom::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('school_year_id', $year->id))
            ->whereHas('level', fn ($q) => $q->whereIn('cycle', $cycles))
            ->with('level')
            ->get()
            ->sort(fn ($a, $b) => [$a->level->order ?? 9999, $a->full_name] <=> [$b->level->order ?? 9999, $b->full_name])
            ->values();

        if ($classrooms->isEmpty()) {
            $this->error("Aucune classe (cycles : ".implode(', ', $cycles).") pour l'année {$year->name}.");

            return self::FAILURE;
        }

        // Niveau scolaire (1..N) par ordre croissant, pour recalculer un âge
        // plausible indépendamment des valeurs exactes de « order ».
        $gradeByLevel = [];
        foreach ($classrooms->pluck('level')->unique('id')->sortBy('order')->values() as $i => $level) {
            $gradeByLevel[$level->id] = $i + 1;
        }

        $students = self::dataset();

        // Répartition round-robin (équilibre les classes, plafond perClass).
        $assignments = [];
        foreach ($classrooms as $classroom) {
            $assignments[$classroom->id] = [];
        }
        $count = $classrooms->count();
        $cursor = 0;
        $placed = 0;
        foreach ($students as $row) {
            $tries = 0;
            while ($tries < $count && count($assignments[$classrooms[$cursor % $count]->id]) >= $perClass) {
                $cursor++;
                $tries++;
            }
            $target = $classrooms[$cursor % $count];
            if (count($assignments[$target->id]) >= $perClass) {
                break; // toutes les classes sont pleines
            }
            $assignments[$target->id][] = $row;
            $cursor++;
            $placed++;
        }

        $this->info(($write ? 'INSERTION' : 'SIMULATION (dry-run)')." — année {$year->name}, max {$perClass}/classe");
        $this->table(
            ['Classe', 'Cycle', 'Élèves'],
            $classrooms->map(fn ($c) => [$c->full_name, $c->level->cycle, count($assignments[$c->id])])->all(),
        );
        $this->line("À insérer : {$placed} / ".count($students)." élèves disponibles.");

        if (! $write) {
            $this->warn('Simulation uniquement — aucune écriture. Relance avec --force pour insérer.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($classrooms, $assignments, $gradeByLevel, $year, &$created, &$updated): void {
            foreach ($classrooms as $classroom) {
                $grade = $gradeByLevel[$classroom->level->id] ?? 1;
                $seq = 0;
                foreach ($assignments[$classroom->id] as $row) {
                    $seq++;
                    [$nom, $postnom, $prenom, $naissance, $lieu, $pere, $mere, $tel] = $row;
                    $reg = sprintf('DEMO-%d-%02d', $classroom->id, $seq);
                    $existed = Student::query()->where('registration_number', $reg)->exists();

                    Student::query()->updateOrCreate(
                        ['registration_number' => $reg],
                        [
                            'classroom_id' => $classroom->id,
                            'enrollment_school_year_id' => $year->id,
                            'first_name' => $prenom,
                            'last_name' => trim($nom.' '.$postnom),
                            'date_of_birth' => self::birthDate($naissance, $year, $grade),
                            'place_of_birth' => $lieu !== '' ? $lieu : null,
                            'gender' => self::guessGender($prenom),
                            'nationality' => 'Congolaise (RDC)',
                            'enrollment_status' => 'actif',
                            'enrolled_on' => $year->starts_on ?? now(),
                            'father_name' => $pere !== '' ? $pere : null,
                            'mother_name' => $mere !== '' ? $mere : null,
                            'primary_phone' => $tel !== '' ? $tel : null,
                        ],
                    );

                    $existed ? $updated++ : $created++;
                }
            }
        });

        $this->info("Terminé : {$created} créé(s), {$updated} mis à jour.");

        return self::SUCCESS;
    }

    /** Âge cible ≈ 5 + niveau (1re primaire ≈ 6 ans … 8e CTEB ≈ 13 ans). */
    private static function birthDate(string $naissance, SchoolYear $year, int $grade): string
    {
        $startYear = (int) Carbon::parse($year->starts_on ?? now())->year;
        $birthYear = $startYear - (5 + $grade);

        try {
            $parsed = Carbon::parse($naissance);
            $month = $parsed->month;
            $day = min($parsed->day, 28);
        } catch (\Throwable) {
            $month = 9;
            $day = 1;
        }

        return Carbon::create($birthYear, $month, $day, 0, 0, 0)->toDateString();
    }

    private static function guessGender(string $prenom): string
    {
        $female = ['Divine', 'Grace', 'Sarah', 'Rachel', 'Esther', 'Ruth', 'Marie', 'Merveille', 'Plamedie'];

        return in_array($prenom, $female, true) ? 'F' : 'M';
    }

    /**
     * Jeu de démonstration (100 contacts, RDC) : [Nom, Postnom, Prénom,
     * Naissance, Lieu, Père, Mère, Téléphone].
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string}>
     */
    private static function dataset(): array
    {
        return [
        ['Ilunga', 'Kabongo', 'Jean', '1985-05-12', 'Lubumbashi', 'Ilunga Pierre', 'Kasongo Marie', '+243812345670'],
        ['Mukendi', 'Kalala', 'Paul', '1990-08-23', 'Kinshasa', 'Mukendi Joseph', 'Mbuyi Sarah', '+243991234561'],
        ['Mutombo', 'Kazadi', 'David', '1992-11-04', 'Mbuji-Mayi', 'Mutombo Jean', 'Ngalula Rachel', '+243823456782'],
        ['Ngandu', 'Tshibangu', 'Emmanuel', '1988-02-15', 'Kananga', 'Ngandu Paul', 'Mbombo Grace', '+243974561233'],
        ['Kasongo', 'Mulumba', 'Daniel', '1995-07-30', 'Kolwezi', 'Kasongo Luc', 'Kapinga Ruth', '+243895672344'],
        ['Kabamba', 'Kabasele', 'Moïse', '1991-09-18', 'Goma', 'Kabamba Marc', 'Tshika Esther', '+243816783455'],
        ['Luvumbu', 'Mbuyi', 'Christian', '1989-12-22', 'Bukavu', 'Luvumbu David', 'Mwamba Marie', '+243997894566'],
        ['Kanku', 'Kapinga', 'Plamedie', '1998-03-05', 'Lubumbashi', 'Kanku Jean', 'Banza Sarah', '+243828905677'],
        ['Mbombo', 'Ngalula', 'Divine', '2000-06-11', 'Kinshasa', 'Mbombo Joseph', 'Kyungu Rachel', '+243979016788'],
        ['Tshika', 'Mwamba', 'Exaucé', '1996-10-28', 'Matadi', 'Tshika Paul', 'Mwila Grace', '+243890127899'],
        ['Mbemba', 'Banza', 'Joël', '1987-01-14', 'Kikwit', 'Mbemba Luc', 'Merveille Ruth', '+243811238900'],
        ['Kyungu', 'Mwila', 'Gloire', '1993-04-09', 'Lubumbashi', 'Kyungu Marc', 'Kasongo Esther', '+243992349011'],
        ['Mwamba', 'Merveille', 'Grace', '1994-08-17', 'Kinshasa', 'Mwamba David', 'Mbuyi Marie', '+243823450122'],
        ['Banza', 'Ilunga', 'Sarah', '1997-11-25', 'Kolwezi', 'Banza Jean', 'Ngalula Sarah', '+243974561233'],
        ['Mwila', 'Mukendi', 'Rachel', '1999-02-02', 'Goma', 'Mwila Joseph', 'Mbombo Rachel', '+243895672344'],
        ['Merveille', 'Mutombo', 'Esther', '2001-05-19', 'Bukavu', 'Merveille Paul', 'Kapinga Grace', '+243816783455'],
        ['Ilunga', 'Ngandu', 'Ruth', '1986-10-07', 'Kananga', 'Ilunga Luc', 'Tshika Ruth', '+243997894566'],
        ['Kabongo', 'Kasongo', 'Marie', '1984-12-21', 'Mbuji-Mayi', 'Kabongo Marc', 'Mwamba Esther', '+243828905677'],
        ['Kalala', 'Kabamba', 'Pierre', '1992-03-10', 'Lubumbashi', 'Kalala David', 'Banza Marie', '+243979016788'],
        ['Kazadi', 'Luvumbu', 'Joseph', '1990-06-29', 'Kinshasa', 'Kazadi Jean', 'Kyungu Sarah', '+243890127899'],
        ['Tshibangu', 'Kanku', 'Marc', '1988-09-16', 'Matadi', 'Tshibangu Joseph', 'Mwila Rachel', '+243811238900'],
        ['Mulumba', 'Mbombo', 'Luc', '1995-11-04', 'Kikwit', 'Mulumba Paul', 'Merveille Grace', '+243992349011'],
        ['Kabasele', 'Tshika', 'David', '1991-01-23', 'Lubumbashi', 'Kabasele Luc', 'Kasongo Ruth', '+243823450122'],
        ['Mbuyi', 'Mbemba', 'Jean', '1989-04-12', 'Kolwezi', 'Mbuyi Marc', 'Mbuyi Esther', '+243974561233'],
        ['Kapinga', 'Kyungu', 'Paul', '1998-08-30', 'Goma', 'Kapinga David', 'Ngalula Marie', '+243895672344'],
        ['Ngalula', 'Mwamba', 'Emmanuel', '2000-12-18', 'Bukavu', 'Ngalula Jean', 'Mbombo Sarah', '+243816783455'],
        ['Mwamba', 'Banza', 'Daniel', '1996-02-05', 'Kananga', 'Mwamba Joseph', 'Kapinga Rachel', '+243997894566'],
        ['Banza', 'Mwila', 'Moïse', '1987-05-24', 'Mbuji-Mayi', 'Banza Paul', 'Tshika Grace', '+243828905677'],
        ['Kyungu', 'Merveille', 'Christian', '1993-09-13', 'Lubumbashi', 'Kyungu Luc', 'Mwamba Ruth', '+243979016788'],
        ['Mwila', 'Ilunga', 'Plamedie', '1994-11-01', 'Kinshasa', 'Mwila Marc', 'Banza Esther', '+243890127899'],
        ['Merveille', 'Mukendi', 'Divine', '1997-03-20', 'Matadi', 'Merveille David', 'Kyungu Marie', '+243811238900'],
        ['Ilunga', 'Mutombo', 'Exaucé', '1999-07-08', 'Kikwit', 'Ilunga Jean', 'Mwila Sarah', '+243992349011'],
        ['Kabongo', 'Ngandu', 'Joël', '2001-10-27', 'Lubumbashi', 'Kabongo Joseph', 'Merveille Rachel', '+243823450122'],
        ['Kalala', 'Kasongo', 'Gloire', '1986-01-15', 'Kolwezi', 'Kalala Paul', 'Kasongo Grace', '+243974561233'],
        ['Kazadi', 'Kabamba', 'Grace', '1984-06-03', 'Goma', 'Kazadi Luc', 'Mbuyi Ruth', '+243895672344'],
        ['Tshibangu', 'Luvumbu', 'Sarah', '1992-08-22', 'Bukavu', 'Tshibangu Marc', 'Ngalula Esther', '+243816783455'],
        ['Mulumba', 'Kanku', 'Rachel', '1990-11-11', 'Kananga', 'Mulumba David', 'Mbombo Marie', '+243997894566'],
        ['Kabasele', 'Mbombo', 'Esther', '1988-02-01', 'Mbuji-Mayi', 'Kabasele Jean', 'Kapinga Sarah', '+243828905677'],
        ['Mbuyi', 'Tshika', 'Ruth', '1995-04-19', 'Lubumbashi', 'Mbuyi Joseph', 'Tshika Rachel', '+243979016788'],
        ['Kapinga', 'Mbemba', 'Marie', '1991-09-07', 'Kinshasa', 'Kapinga Paul', 'Mwamba Grace', '+243890127899'],
        ['Ngalula', 'Kyungu', 'Pierre', '1989-12-26', 'Matadi', 'Ngalula Luc', 'Banza Ruth', '+243811238900'],
        ['Mwamba', 'Mwamba', 'Joseph', '1998-03-14', 'Kikwit', 'Mwamba Marc', 'Kyungu Esther', '+243992349011'],
        ['Banza', 'Banza', 'Marc', '2000-07-03', 'Lubumbashi', 'Banza David', 'Mwila Marie', '+243823450122'],
        ['Kyungu', 'Mwila', 'Luc', '1996-10-21', 'Kolwezi', 'Kyungu Jean', 'Merveille Sarah', '+243974561233'],
        ['Mwila', 'Merveille', 'David', '1987-01-10', 'Goma', 'Mwila Joseph', 'Kasongo Rachel', '+243895672344'],
        ['Merveille', 'Ilunga', 'Jean', '1993-05-30', 'Bukavu', 'Merveille Paul', 'Mbuyi Grace', '+243816783455'],
        ['Ilunga', 'Kabongo', 'Paul', '1994-09-17', 'Kananga', 'Ilunga Luc', 'Ngalula Ruth', '+243997894566'],
        ['Mukendi', 'Kalala', 'Emmanuel', '1997-12-06', 'Mbuji-Mayi', 'Mukendi Marc', 'Mbombo Esther', '+243828905677'],
        ['Mutombo', 'Kazadi', 'Daniel', '1999-02-25', 'Lubumbashi', 'Mutombo David', 'Kapinga Marie', '+243979016788'],
        ['Ngandu', 'Tshibangu', 'Moïse', '2001-05-14', 'Kinshasa', 'Ngandu Jean', 'Tshika Sarah', '+243890127899'],
        ['Kasongo', 'Mulumba', 'Christian', '1986-10-02', 'Matadi', 'Kasongo Joseph', 'Mwamba Rachel', '+243811238900'],
        ['Kabamba', 'Kabasele', 'Plamedie', '1984-01-21', 'Kikwit', 'Kabamba Paul', 'Banza Grace', '+243992349011'],
        ['Luvumbu', 'Mbuyi', 'Divine', '1992-04-11', 'Lubumbashi', 'Luvumbu Luc', 'Kyungu Ruth', '+243823450122'],
        ['Kanku', 'Kapinga', 'Exaucé', '1990-08-29', 'Kolwezi', 'Kanku Marc', 'Mwila Esther', '+243974561233'],
        ['Mbombo', 'Ngalula', 'Joël', '1988-11-17', 'Goma', 'Mbombo David', 'Merveille Marie', '+243895672344'],
        ['Tshika', 'Mwamba', 'Gloire', '1995-02-07', 'Bukavu', 'Tshika Jean', 'Kasongo Sarah', '+243816783455'],
        ['Mbemba', 'Banza', 'Grace', '1991-06-26', 'Kananga', 'Mbemba Joseph', 'Mbuyi Rachel', '+243997894566'],
        ['Kyungu', 'Mwila', 'Sarah', '1989-09-14', 'Mbuji-Mayi', 'Kyungu Paul', 'Ngalula Grace', '+243828905677'],
        ['Mwamba', 'Merveille', 'Rachel', '1998-12-02', 'Lubumbashi', 'Mwamba Luc', 'Mbombo Ruth', '+243979016788'],
        ['Banza', 'Ilunga', 'Esther', '2000-03-23', 'Kinshasa', 'Banza Marc', 'Kapinga Esther', '+243890127899'],
        ['Mwila', 'Mukendi', 'Ruth', '1996-07-11', 'Matadi', 'Mwila David', 'Tshika Marie', '+243811238900'],
        ['Merveille', 'Mutombo', 'Marie', '1987-10-29', 'Kikwit', 'Merveille Jean', 'Mwamba Sarah', '+243992349011'],
        ['Ilunga', 'Ngandu', 'Pierre', '1993-01-18', 'Lubumbashi', 'Ilunga Joseph', 'Banza Rachel', '+243823450122'],
        ['Kabongo', 'Kasongo', 'Joseph', '1994-05-08', 'Kolwezi', 'Kabongo Paul', 'Kyungu Grace', '+243974561233'],
        ['Kalala', 'Kabamba', 'Marc', '1997-09-26', 'Goma', 'Kalala Luc', 'Mwila Ruth', '+243895672344'],
        ['Kazadi', 'Luvumbu', 'Luc', '1999-12-15', 'Bukavu', 'Kazadi Marc', 'Merveille Esther', '+243816783455'],
        ['Tshibangu', 'Kanku', 'David', '2001-04-04', 'Kananga', 'Tshibangu David', 'Kasongo Marie', '+243997894566'],
        ['Mulumba', 'Mbombo', 'Jean', '1986-07-22', 'Mbuji-Mayi', 'Mulumba Jean', 'Mbuyi Sarah', '+243828905677'],
        ['Kabasele', 'Tshika', 'Paul', '1984-10-10', 'Lubumbashi', 'Kabasele Joseph', 'Ngalula Rachel', '+243979016788'],
        ['Mbuyi', 'Mbemba', 'Emmanuel', '1992-01-01', 'Kinshasa', 'Mbuyi Paul', 'Mbombo Grace', '+243890127899'],
        ['Kapinga', 'Kyungu', 'Daniel', '1990-04-20', 'Matadi', 'Kapinga Luc', 'Kapinga Ruth', '+243811238900'],
        ['Ngalula', 'Mwamba', 'Moïse', '1988-08-09', 'Kikwit', 'Ngalula Marc', 'Tshika Esther', '+243992349011'],
        ['Mwamba', 'Banza', 'Christian', '1995-11-27', 'Lubumbashi', 'Mwamba David', 'Mwamba Marie', '+243823450122'],
        ['Banza', 'Mwila', 'Plamedie', '1991-02-17', 'Kolwezi', 'Banza Jean', 'Banza Sarah', '+243974561233'],
        ['Kyungu', 'Merveille', 'Divine', '1989-06-06', 'Goma', 'Kyungu Joseph', 'Kyungu Rachel', '+243895672344'],
        ['Mwila', 'Ilunga', 'Exaucé', '1998-09-25', 'Bukavu', 'Mwila Paul', 'Mwila Grace', '+243816783455'],
        ['Merveille', 'Mukendi', 'Joël', '2000-12-14', 'Kananga', 'Merveille Luc', 'Merveille Ruth', '+243997894566'],
        ['Ilunga', 'Mutombo', 'Gloire', '1996-04-03', 'Mbuji-Mayi', 'Ilunga Marc', 'Kasongo Esther', '+243828905677'],
        ['Kabongo', 'Ngandu', 'Grace', '1987-07-21', 'Lubumbashi', 'Kabongo David', 'Mbuyi Marie', '+243979016788'],
        ['Kalala', 'Kasongo', 'Sarah', '1993-11-10', 'Kinshasa', 'Kalala Jean', 'Ngalula Sarah', '+243890127899'],
        ['Kazadi', 'Kabamba', 'Rachel', '1994-02-01', 'Matadi', 'Kazadi Joseph', 'Mbombo Rachel', '+243811238900'],
        ['Tshibangu', 'Luvumbu', 'Esther', '1997-05-20', 'Kikwit', 'Tshibangu Paul', 'Kapinga Grace', '+243992349011'],
        ['Mulumba', 'Kanku', 'Ruth', '1999-09-08', 'Lubumbashi', 'Mulumba Luc', 'Tshika Ruth', '+243823450122'],
        ['Kabasele', 'Mbombo', 'Marie', '2001-12-27', 'Kolwezi', 'Kabasele Marc', 'Mwamba Esther', '+243974561233'],
        ['Mbuyi', 'Tshika', 'Pierre', '1986-04-16', 'Goma', 'Mbuyi David', 'Banza Marie', '+243895672344'],
        ['Kapinga', 'Mbemba', 'Joseph', '1984-08-05', 'Bukavu', 'Kapinga Jean', 'Kyungu Sarah', '+243816783455'],
        ['Ngalula', 'Kyungu', 'Marc', '1992-11-24', 'Kananga', 'Ngalula Joseph', 'Mwila Rachel', '+243997894566'],
        ['Mwamba', 'Mwamba', 'Luc', '1990-02-13', 'Mbuji-Mayi', 'Mwamba Paul', 'Merveille Grace', '+243828905677'],
        ['Banza', 'Banza', 'David', '1988-06-02', 'Lubumbashi', 'Banza Luc', 'Kasongo Ruth', '+243979016788'],
        ['Kyungu', 'Mwila', 'Jean', '1995-09-21', 'Kinshasa', 'Kyungu Marc', 'Mbuyi Esther', '+243890127899'],
        ['Mwila', 'Merveille', 'Paul', '1991-12-10', 'Matadi', 'Mwila David', 'Ngalula Marie', '+243811238900'],
        ['Merveille', 'Ilunga', 'Emmanuel', '1989-03-31', 'Kikwit', 'Merveille Jean', 'Mbombo Sarah', '+243992349011'],
        ['Ilunga', 'Kabongo', 'Daniel', '1998-07-19', 'Lubumbashi', 'Ilunga Joseph', 'Kapinga Rachel', '+243823450122'],
        ['Mukendi', 'Kalala', 'Moïse', '2000-11-07', 'Kolwezi', 'Mukendi Paul', 'Tshika Grace', '+243974561233'],
        ['Mutombo', 'Kazadi', 'Christian', '1996-02-26', 'Goma', 'Mutombo Luc', 'Mwamba Ruth', '+243895672344'],
        ['Ngandu', 'Tshibangu', 'Plamedie', '1987-06-15', 'Bukavu', 'Ngandu Marc', 'Banza Esther', '+243816783455'],
        ['Kasongo', 'Mulumba', 'Divine', '1993-10-04', 'Kananga', 'Kasongo David', 'Kyungu Marie', '+243997894566'],
        ['Kabamba', 'Kabasele', 'Exaucé', '1994-01-23', 'Mbuji-Mayi', 'Kabamba Jean', 'Mwila Sarah', '+243828905677'],
        ['Luvumbu', 'Mbuyi', 'Joël', '1997-05-12', 'Lubumbashi', 'Luvumbu Joseph', 'Merveille Rachel', '+243979016788'],
        ['Kanku', 'Kapinga', 'Gloire', '1999-08-31', 'Kinshasa', 'Kanku Paul', 'Kasongo Grace', '+243890127899'],
        ];
    }
}
