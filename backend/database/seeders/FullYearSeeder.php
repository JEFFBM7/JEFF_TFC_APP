<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\TermGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Peuple ENTIÈREMENT l'année scolaire courante : pour chaque classe de l'année,
 * élèves + parents + matières (coefficients) + affectations enseignants +
 * emploi du temps + évaluations + notes + présences.
 *
 *   php artisan db:seed --class=FullYearSeeder
 *
 * Idempotent (matricules/emails déterministes) : relancer met à jour sans doublon.
 */
class FullYearSeeder extends Seeder
{
    private const STUDENTS_PER_CLASS = 10;

    public function run(): void
    {
        $year = SchoolYear::where('is_current', true)->first();
        if ($year === null) {
            $this->command->warn('Aucune année courante : définis une année courante d\'abord.');

            return;
        }

        if ($year->terms()->count() === 0) {
            app(TermGenerationService::class)->generateForYear($year);
        }
        $termsByCycle = $year->terms()->with('periods')->orderBy('position')->get()
            ->groupBy('applicable_cycle');

        // Matières communes (coefficients en regard).
        $subjectDefs = [
            ['Mathématiques', 4], ['Français', 4], ['Anglais', 2],
            ['Sciences naturelles', 2], ['Histoire-Géo', 2], ['Éducation physique', 1],
        ];
        $subjects = collect($subjectDefs)->map(fn ($d) => [
            'model' => Subject::firstOrCreate(['name' => $d[0]]),
            'coef' => $d[1],
        ]);

        // Pool d'enseignants réutilisés sur toutes les classes.
        $teachers = collect(range(1, 18))->map(function (int $n) use ($subjects): Teacher {
            $user = User::updateOrCreate(
                ['email' => "prof{$n}@malunga.test"],
                ['name' => "Prof Démo {$n}", 'password' => Hash::make('password'),
                    'role' => UserRole::Enseignant, 'email_verified_at' => now()],
            );

            return Teacher::updateOrCreate(['user_id' => $user->id], [
                'speciality' => $subjects[($n - 1) % $subjects->count()]['model']->name,
            ]);
        });

        $classrooms = ClassRoom::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('school_year_id', $year->id))
            ->with('level')->orderBy('id')->get();

        $faker = fake('fr_FR');
        $times = [['07:30', '09:00'], ['09:15', '10:45'], ['11:00', '12:30']];
        $studentTotal = 0;

        DB::transaction(function () use (
            $classrooms, $subjects, $teachers, $termsByCycle, $year, $faker, $times, &$studentTotal
        ): void {
            foreach ($classrooms as $ci => $cls) {
                $cycle = $cls->level?->cycle;
                $termCycle = in_array($cycle, [Level::CYCLE_SECONDAIRE, Level::CYCLE_CTEB], true)
                    ? Term::CYCLE_SECONDAIRE : Term::CYCLE_PRIMAIRE;
                $terms = $termsByCycle[$termCycle] ?? collect();
                $portalEligible = in_array($cycle, [Level::CYCLE_CTEB, Level::CYCLE_SECONDAIRE], true);

                // Matières + coefficients de la classe.
                foreach ($subjects as $entry) {
                    if (! $cls->subjects()->where('subjects.id', $entry['model']->id)->exists()) {
                        $cls->subjects()->attach($entry['model']->id, ['coefficient' => $entry['coef']]);
                    }
                }

                // Affectation d'un enseignant par matière + emploi du temps.
                $classTeacher = [];
                foreach ($subjects as $si => $entry) {
                    $subject = $entry['model'];
                    $teacher = $teachers[($ci + $si) % $teachers->count()];
                    $classTeacher[$subject->id] = $teacher;

                    TeacherAssignment::firstOrCreate([
                        'teacher_id' => $teacher->id, 'classroom_id' => $cls->id,
                        'subject_id' => $subject->id, 'school_year_id' => $year->id,
                    ]);

                    $slot = $times[intdiv($si, 5) % count($times)];
                    TimetableSlot::firstOrCreate([
                        'classroom_id' => $cls->id, 'subject_id' => $subject->id,
                        'teacher_id' => $teacher->id, 'school_year_id' => $year->id,
                        'day_of_week' => ($si % 5) + 1, 'starts_at' => $slot[0], 'ends_at' => $slot[1],
                    ]);
                }

                // Élèves (+ parent 1 sur 3).
                $students = [];
                for ($i = 1; $i <= self::STUDENTS_PER_CLASS; $i++) {
                    $reg = sprintf('FULL-%d-%02d', $cls->id, $i);
                    $first = $faker->firstName();
                    $last = mb_strtoupper($faker->lastName());

                    $studentUser = $portalEligible ? User::updateOrCreate(
                        ['email' => strtolower("eleve.{$reg}@malunga.local")],
                        ['name' => "{$first} {$last}", 'password' => Hash::make('password'),
                            'role' => UserRole::Eleve, 'is_active' => true, 'email_verified_at' => now()],
                    ) : null;

                    $student = Student::updateOrCreate(
                        ['registration_number' => $reg],
                        [
                            'user_id' => $studentUser?->id, 'classroom_id' => $cls->id,
                            'enrollment_school_year_id' => $year->id,
                            'first_name' => $first, 'last_name' => $last,
                            'date_of_birth' => $faker->dateTimeBetween('-18 years', '-5 years')->format('Y-m-d'),
                            'gender' => $faker->randomElement(['M', 'F']), 'enrollment_status' => 'actif',
                        ],
                    );
                    $students[] = $student;
                    $studentTotal++;

                    if ($i % 3 === 0) {
                        $parentUser = User::updateOrCreate(
                            ['email' => strtolower("parent.{$reg}@malunga.local")],
                            ['name' => "Parent {$last}", 'password' => Hash::make('password'),
                                'role' => UserRole::Parent, 'email_verified_at' => now()],
                        );
                        $profile = ParentProfile::updateOrCreate(
                            ['user_id' => $parentUser->id],
                            ['phone' => '+243 8'.rand(10000000, 99999999), 'address' => 'Kinshasa'],
                        );
                        if (! $student->parents()->where('parent_profiles.id', $profile->id)->exists()) {
                            $student->parents()->attach($profile->id, ['relation' => 'pere']);
                        }
                    }
                }

                // Évaluations (1 par matière, 1er trimestre du cycle) + notes.
                $term = $terms->first();
                if ($term !== null) {
                    $period = $term->periods->first();
                    $heldOn = $this->dateStr($period?->starts_on ?? $term->starts_on ?? $year->starts_on);
                    foreach ($subjects as $entry) {
                        $subject = $entry['model'];
                        $eval = Evaluation::updateOrCreate(
                            ['classroom_id' => $cls->id, 'subject_id' => $subject->id,
                                'term_id' => $term->id, 'name' => 'Interrogation '.$subject->name],
                            ['period_id' => $period?->id, 'type' => Evaluation::TYPE_INTERROGATION,
                                'held_on' => $heldOn, 'max_value' => 20,
                                'teacher_id' => $classTeacher[$subject->id]->id, 'published_at' => now()],
                        );
                        foreach ($students as $student) {
                            Grade::updateOrCreate(
                                ['evaluation_id' => $eval->id, 'student_id' => $student->id],
                                ['value' => rand(6, 19), 'absent' => false],
                            );
                        }
                    }
                }

                // Présences (un échantillon).
                foreach (array_slice($students, 0, 3) as $k => $student) {
                    Attendance::updateOrCreate(
                        ['student_id' => $student->id, 'date' => $this->dateStr($year->starts_on), 'subject_id' => null],
                        ['classroom_id' => $cls->id,
                            'status' => [Attendance::STATUS_PRESENT, Attendance::STATUS_ABSENT, Attendance::STATUS_LATE][$k % 3],
                            'justified' => false],
                    );
                }
            }
        });

        $this->command->info(
            "Année {$year->name} entièrement peuplée : {$classrooms->count()} classes, "
            ."{$studentTotal} élèves, profs/affectations/emploi du temps/évaluations/notes/présences."
        );
    }

    private function dateStr(mixed $value): string
    {
        return Carbon::parse($value)->toDateString();
    }
}
