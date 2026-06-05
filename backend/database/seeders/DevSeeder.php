<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\Period;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Models\User;
use App\Services\TermGenerationService;
use App\Support\SchoolOptionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de démonstration — remplit la base avec des données représentatives
 * du Complexe scolaire MALUNGA pour tester toutes les fonctionnalités.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        // ── Année scolaire & trimestres ───────────────────────────────────
        $year = SchoolYear::updateOrCreate(
            ['name' => '2025-2026'],
            ['starts_on' => '2025-09-01', 'ends_on' => '2026-07-31', 'is_current' => true],
        );

        // ── Génération auto de la structure Terms + Périodes ────────────
        /** @var TermGenerationService $termGeneration */
        $termGeneration = app(TermGenerationService::class);
        $termGeneration->generateForYear($year);

        // Récupérer les trimestres du cycle primaire (pour les évaluations)
        $primaryTerms = Term::query()
            ->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_PRIMAIRE)
            ->orderBy('position')
            ->get();
        $t1 = $primaryTerms->firstWhere('position', 1);
        $t2 = $primaryTerms->firstWhere('position', 2);
        $t3 = $primaryTerms->firstWhere('position', 3);

        // Indexer les périodes par term_id pour récupérer facilement
        $primaryTerms->each(fn (Term $t) => $t->load('periods'));
        $periodsByTerm = $primaryTerms->mapWithKeys(
            fn (Term $t) => [$t->id => $t->periods->all()]
        )->all();

        // ── Cycles, niveaux & classes ─────────────────────────────────────
        $levels = [];
        foreach ([
            ['1ère maternelle', Level::CYCLE_MATERNEL, 1],
            ['2e maternelle', Level::CYCLE_MATERNEL, 2],
            ['3e maternelle', Level::CYCLE_MATERNEL, 3],
            ['1ère primaire', Level::CYCLE_PRIMAIRE, 10],
            ['2e primaire', Level::CYCLE_PRIMAIRE, 11],
            ['3e primaire', Level::CYCLE_PRIMAIRE, 12],
            ['4e primaire', Level::CYCLE_PRIMAIRE, 13],
            ['5e primaire', Level::CYCLE_PRIMAIRE, 14],
            ['6e primaire', Level::CYCLE_PRIMAIRE, 15],
            ['7e CTEB', Level::CYCLE_CTEB, 20],
            ['8e CTEB', Level::CYCLE_CTEB, 21],
            ['1ère secondaire', Level::CYCLE_SECONDAIRE, 30],
            ['2e secondaire', Level::CYCLE_SECONDAIRE, 31],
            ['3e secondaire', Level::CYCLE_SECONDAIRE, 32],
            ['4e secondaire', Level::CYCLE_SECONDAIRE, 33],
        ] as [$name, $cycle, $order]) {
            $levels[$name] = Level::updateOrCreate(['name' => $name], ['cycle' => $cycle, 'order' => $order]);
        }

        $p5 = $levels['5e primaire'];
        $p4 = $levels['4e primaire'];
        $p3 = $levels['3e primaire'];
        $s1 = $levels['1ère secondaire'];

        // ── Options officielles des Humanités (EPST/RDC) ──
        foreach (SchoolOptionCatalog::rows() as $row) {
            SchoolOption::updateOrCreate(
                ['name' => $row['name']],
                ['filiere' => $row['filiere']],
            );
        }
        $mecanique = SchoolOption::where('name', 'Mécanique')->first();

        $cls5A = ClassRoom::updateOrCreate(['level_id' => $p5->id, 'section' => 'A', 'option' => ''], ['school_option_id' => null]);
        $cls5B = ClassRoom::updateOrCreate(['level_id' => $p5->id, 'section' => 'B', 'option' => ''], ['school_option_id' => null]);
        $cls4A = ClassRoom::updateOrCreate(['level_id' => $p4->id, 'section' => 'A', 'option' => ''], ['school_option_id' => null]);
        $cls3A = ClassRoom::updateOrCreate(['level_id' => $p3->id, 'section' => 'A', 'option' => ''], ['school_option_id' => null]);
        $clsS1MecaA = ClassRoom::updateOrCreate(
            ['level_id' => $s1->id, 'section' => 'A', 'option' => $mecanique->name],
            ['school_option_id' => $mecanique->id],
        );

        // ── Cours / Matières (catalogue officiel EPST/RDC) ───────────────
        // Matières historiques (gardées pour rétro-compatibilité données).
        $maths = Subject::updateOrCreate(['name' => 'Mathématiques'], ['description' => 'Algèbre, géométrie, statistiques']);
        $francais = Subject::updateOrCreate(['name' => 'Français'], ['description' => 'Langue, grammaire, littérature']);
        $svt = Subject::updateOrCreate(['name' => 'Sciences naturelles'], ['description' => 'Biologie et géologie (SVT)']);
        $histoire = Subject::updateOrCreate(['name' => 'Histoire-Géo'], ['description' => 'Histoire et géographie']);
        $physique = Subject::updateOrCreate(['name' => 'Physique-Chimie'], ['description' => 'Sciences physiques']);
        $anglais = Subject::updateOrCreate(['name' => 'Anglais'], ['description' => 'Langue anglaise']);
        $eps = Subject::updateOrCreate(['name' => 'Éducation physique'], ['description' => 'EPS']);

        // Catalogue enrichi conforme aux programmes nationaux RDC.
        $extraSubjects = [
            ['Éducation civique et morale', 'ECM — valeurs de la Nouvelle Citoyenneté'],
            ['Langues nationales', 'Lingala, Kikongo, Tshiluba, Swahili'],
            ['Éveil scientifique', 'Sciences, histoire et géographie au primaire'],
            ['Arts plastiques', 'Dessin, peinture, modelage'],
            ['Musique', 'Éducation musicale et chant'],
            ['Religion', 'Éducation religieuse'],
            ['Philosophie', 'Pensée critique et histoire des idées (secondaire)'],
            ['Économie', 'Économie générale (secondaire)'],
            ['Informatique', 'TIC — Technologies de l\'information et de la communication'],
            ['Biologie', 'Sciences de la vie'],
            ['Chimie', 'Sciences de la matière'],
            ['Physique', 'Sciences physiques'],
            ['Géographie', 'Géographie physique et humaine'],
            ['Histoire', 'Histoire générale et de la RDC'],
        ];
        foreach ($extraSubjects as [$name, $description]) {
            Subject::updateOrCreate(['name' => $name], ['description' => $description]);
        }

        // Coefficients par classe
        foreach ([$cls5A, $cls5B] as $cls) {
            foreach ([[$maths, 4], [$francais, 4], [$svt, 2], [$histoire, 2], [$physique, 3], [$anglais, 2], [$eps, 1]] as [$sub, $coef]) {
                if (! $cls->subjects()->where('subjects.id', $sub->id)->exists()) {
                    $cls->subjects()->attach($sub->id, ['coefficient' => $coef]);
                }
            }
        }
        foreach ([$cls4A, $cls3A, $clsS1MecaA] as $cls) {
            foreach ([[$maths, 5], [$francais, 4], [$svt, 3], [$histoire, 2], [$physique, 4], [$anglais, 2], [$eps, 1]] as [$sub, $coef]) {
                if (! $cls->subjects()->where('subjects.id', $sub->id)->exists()) {
                    $cls->subjects()->attach($sub->id, ['coefficient' => $coef]);
                }
            }
        }

        // ── Comptes enseignants ───────────────────────────────────────────
        $teachers = $this->makeTeachers([
            ['Makela Kabila',   'mkabila@malunga.test',   'Mathématiques'],
            ['Nzuzi Lusambo',   'nlusambo@malunga.test',  'Français'],
            ['Bupe Ilunga',     'bilunga@malunga.test',   'Sciences naturelles'],
            ['Tshiama Ntumba',  'tntumba@malunga.test',   'Histoire-Géographie'],
            ['Kosi Nsangama',   'knsangama@malunga.test', 'Physique-Chimie'],
        ]);

        // Affectations enseignants
        $assignments = [
            [$teachers[0], $cls5A, $maths],
            [$teachers[0], $cls5B, $maths],
            [$teachers[0], $cls4A, $maths],
            [$teachers[1], $cls5A, $francais],
            [$teachers[1], $cls5B, $francais],
            [$teachers[2], $cls5A, $svt],
            [$teachers[2], $cls4A, $svt],
            [$teachers[3], $cls4A, $histoire],
            [$teachers[3], $cls3A, $histoire],
            [$teachers[4], $cls3A, $physique],
        ];
        foreach ($assignments as [$teacher, $classroom, $subject]) {
            TeacherAssignment::firstOrCreate([
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'school_year_id' => $year->id,
            ]);
        }

        // ── Emploi du temps — 5e primaire A ──────────────────────────────
        $slots = [
            [$cls5A, $maths,    $teachers[0], 1, '07:30', '09:00'], // Lundi
            [$cls5A, $francais, $teachers[1], 1, '09:15', '10:45'],
            [$cls5A, $svt,      $teachers[2], 2, '07:30', '09:00'], // Mardi
            [$cls5A, $anglais,  $teachers[1], 2, '09:15', '10:45'],
            [$cls5A, $maths,    $teachers[0], 3, '07:30', '09:00'], // Mercredi
            [$cls5A, $physique, $teachers[4], 4, '07:30', '09:00'], // Jeudi
            [$cls5A, $histoire, $teachers[3], 4, '09:15', '10:45'],
            [$cls5A, $francais, $teachers[1], 5, '07:30', '09:00'], // Vendredi
            [$cls5A, $eps,      $teachers[0], 5, '09:15', '10:15'],
        ];
        foreach ($slots as [$cls, $sub, $teacher, $day, $start, $end]) {
            TimetableSlot::firstOrCreate([
                'classroom_id' => $cls->id,
                'subject_id' => $sub->id,
                'teacher_id' => $teacher->id,
                'school_year_id' => $year->id,
                'day_of_week' => $day,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }

        // ── Élèves + parents ──────────────────────────────────────────────
        $studentsData = [
            // 5e primaire A
            ['Tshimanga', 'Jean', '2012-04-10', 'M', 'MAL001', $cls5A, 'parent.tshimanga@test.com', 'Maman Tshimanga', 'mere'],
            ['Mukendi',   'Amina', '2012-08-22', 'F', 'MAL002', $cls5A, 'parent.mukendi@test.com',   'Papa Mukendi',   'pere'],
            ['Kabambi',   'Christelle', '2012-12-05', 'F', 'MAL003', $cls5A, 'parent.kabambi@test.com', 'Tuteur Kabambi', 'tuteur'],
            ['Nsumbu',    'Patrick', '2013-02-14', 'M', 'MAL004', $cls5A, null, null, null],
            ['Luzolo',    'Grace', '2012-07-19', 'F', 'MAL005', $cls5A, 'parent.luzolo@test.com', 'Père Luzolo', 'pere'],
            // 5e primaire B
            ['Banza',     'Serge',   '2012-03-01', 'M', 'MAL006', $cls5B, null, null, null],
            ['Mbemba',    'Sandra',  '2012-09-11', 'F', 'MAL007', $cls5B, 'parent.mbemba@test.com', 'Mère Mbemba', 'mere'],
            // 4e primaire A
            ['Mutombo',   'Jonas',   '2011-05-20', 'M', 'MAL008', $cls4A, null, null, null],
            ['Kayembe',   'Pauline', '2011-11-30', 'F', 'MAL009', $cls4A, null, null, null],
            // 3e primaire A
            ['Nkulu',     'David',   '2010-06-12', 'M', 'MAL010', $cls3A, null, null, null],
        ];

        $createdStudents = [];
        foreach ($studentsData as $sd) {
            [$last, $first, $dob, $gender, $reg, $cls, $parentEmail, $parentName, $relation] = $sd;
            $cls->loadMissing('level');
            $studentPortalEligible = in_array(
                $cls->level?->cycle,
                [Level::CYCLE_CTEB, Level::CYCLE_SECONDAIRE],
                true,
            );

            $studentUser = User::updateOrCreate(
                ['email' => strtolower("{$first}.{$last}@eleve.malunga.test")],
                [
                    'name' => "{$first} {$last}",
                    'password' => Hash::make('password'),
                    'role' => UserRole::Eleve,
                    'is_active' => $studentPortalEligible,
                    'email_verified_at' => now(),
                ],
            );

            $student = Student::updateOrCreate(
                ['registration_number' => $reg],
                [
                    'user_id' => $studentUser->id,
                    'classroom_id' => $cls->id,
                    'enrollment_school_year_id' => $year->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                ],
            );
            $createdStudents[] = $student;

            if ($parentEmail) {
                $parentUser = User::updateOrCreate(
                    ['email' => $parentEmail],
                    ['name' => $parentName, 'password' => Hash::make('password'), 'role' => UserRole::Parent, 'email_verified_at' => now()],
                );
                $profile = ParentProfile::updateOrCreate(
                    ['user_id' => $parentUser->id],
                    ['phone' => '+243 8'.rand(10000000, 99999999), 'address' => 'Kinshasa, '.['Gombe', 'Limete', 'Ngaliema', 'Kalamu'][rand(0, 3)]],
                );
                if (! $student->parents()->where('parent_profiles.id', $profile->id)->exists()) {
                    $student->parents()->attach($profile->id, ['relation' => $relation]);
                }
            }
        }

        // ── Évaluations + notes (T1 et T2 pour 5e primaire A) ───────────
        $students5A = array_filter($createdStudents, fn ($s) => $s->classroom_id === $cls5A->id);

        $evalDefs = [
            [$maths,    $t1, 'Devoir 1 Maths', 'devoir', '2025-10-05', $teachers[0]],
            [$maths,    $t1, 'Examen T1 Maths', 'examen', '2025-12-10', $teachers[0]],
            [$francais, $t1, 'Devoir 1 Français', 'devoir', '2025-10-12', $teachers[1]],
            [$francais, $t1, 'Examen T1 Français', 'examen', '2025-12-11', $teachers[1]],
            [$svt,      $t1, 'Contrôle SVT', 'controle', '2025-11-08', $teachers[2]],
            [$maths,    $t2, 'Devoir 1 T2 Maths', 'devoir', '2026-01-20', $teachers[0]],
            [$maths,    $t2, 'Examen T2 Maths', 'examen', '2026-03-15', $teachers[0]],
            [$francais, $t2, 'Devoir 1 T2 Français', 'devoir', '2026-01-25', $teachers[1]],
            [$physique, $t2, 'Contrôle Physique T2', 'controle', '2026-02-10', $teachers[4]],
        ];

        $notesGrid = [
            'MAL001' => [14, 16, 13, 15, 11, 15, 17, 14, 12],
            'MAL002' => [12, 11, 14, 13, 10, 13, 12, 15, 14],
            'MAL003' => [16, 18, 15, 17, 14, 17, 19, 16, 15],
            'MAL004' => [9,  10,  8, 11,  7, 10, 11,  9,  8],
            'MAL005' => [13, 14, 12, 13, 13, 14, 15, 13, 11],
        ];

        foreach ($evalDefs as $i => [$sub, $term, $name, $type, $date, $teacher]) {
            $period = collect($periodsByTerm[$term->id])
                ->first(fn (Period $period) => $date >= $period->starts_on->toDateString() && $date <= $period->ends_on->toDateString())
                ?? $periodsByTerm[$term->id][0];

            $eval = Evaluation::updateOrCreate(
                ['classroom_id' => $cls5A->id, 'subject_id' => $sub->id, 'term_id' => $term->id, 'name' => $name],
                ['period_id' => $period->id, 'type' => $type, 'held_on' => $date, 'max_value' => 20, 'teacher_id' => $teacher->id],
            );

            foreach ($students5A as $student) {
                if (! isset($notesGrid[$student->registration_number][$i])) {
                    continue;
                }
                Grade::updateOrCreate(
                    ['evaluation_id' => $eval->id, 'student_id' => $student->id],
                    ['value' => $notesGrid[$student->registration_number][$i], 'absent' => false],
                );
            }
        }

        // ── Absences (quelques-unes pour test des alertes) ─────────────────
        $student1 = Student::where('registration_number', 'MAL001')->first();
        $student4 = Student::where('registration_number', 'MAL004')->first();

        if ($student1) {
            foreach (['2026-01-15', '2026-01-16', '2026-01-17'] as $day) {
                Attendance::updateOrCreate(
                    ['student_id' => $student1->id, 'date' => $day, 'subject_id' => null],
                    ['classroom_id' => $cls5A->id, 'status' => 'absent', 'justified' => false],
                );
            }
        }
        if ($student4) {
            foreach (['2026-02-03', '2026-02-04'] as $day) {
                Attendance::updateOrCreate(
                    ['student_id' => $student4->id, 'date' => $day, 'subject_id' => null],
                    ['classroom_id' => $cls5A->id, 'status' => 'absent', 'justified' => true, 'justification' => 'Maladie'],
                );
            }
            Attendance::updateOrCreate(
                ['student_id' => $student4->id, 'date' => '2026-02-10', 'subject_id' => null],
                ['classroom_id' => $cls5A->id, 'status' => 'late', 'justified' => false],
            );
        }

        // ── Secrétariat ───────────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'secretariat@malunga.test'],
            ['name' => 'Secrétariat MALUNGA', 'password' => Hash::make('password'), 'role' => UserRole::Secretariat, 'email_verified_at' => now()],
        );

        $this->command->info('');
        $this->command->info('✅  Données de démonstration insérées avec succès.');
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━ Comptes de test ━━━━━━━━━━━━');
        $this->command->info('🔑 Admin        : admin@educonnect.test         / password');
        $this->command->info('🔑 Enseignant   : mkabila@malunga.test          / password');
        $this->command->info('🔑 Secrétariat  : secretariat@malunga.test      / password');
        $this->command->info('🔑 Parent       : parent.tshimanga@test.com     / password');
        $this->command->info('🔑 Élève        : jean.tshimanga@eleve.malunga.test / password');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /** @return array<int, Teacher> */
    private function makeTeachers(array $defs): array
    {
        $result = [];
        foreach ($defs as [$name, $email, $speciality]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'role' => UserRole::Enseignant, 'email_verified_at' => now()],
            );
            $teacher = Teacher::updateOrCreate(
                ['user_id' => $user->id],
                ['speciality' => $speciality],
            );
            $result[] = $teacher;
        }

        return $result;
    }
}
