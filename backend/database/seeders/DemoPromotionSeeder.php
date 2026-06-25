<?php

namespace Database\Seeders;

use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Données de démo pour tester le « Passage de classe ».
 *
 * Crée un cohorte cohérent inscrit en 3e primaire (année source 2028-2029),
 * avec des notes, prêt à être promu en 4e primaire (année cible 2029-2030).
 * Met 2028-2029 comme année courante.
 *
 *   php artisan db:seed --class=DemoPromotionSeeder
 *
 * Idempotent : relancer ne crée pas de doublons.
 */
class DemoPromotionSeeder extends Seeder
{
    public function run(): void
    {
        $from = SchoolYear::where('name', '2028-2029')->firstOrFail();
        $to = SchoolYear::where('name', '2029-2030')->firstOrFail();

        // Division 3e primaire de l'année source (sans options -> passage simple).
        $level3 = Level::where('name', '3e primaire')->firstOrFail();
        $sourceClass = SchoolClass::where('school_year_id', $from->id)
            ->where('level_id', $level3->id)->with('divisions')->first();
        $sourceClassroom = $sourceClass?->divisions->first();

        if ($sourceClassroom === null) {
            $this->command->warn('Aucune division 3e primaire dans 2028-2029 — seeder ignoré.');

            return;
        }

        // Vérifie que la classe cible 4e primaire existe dans l'année destination.
        $level4 = Level::where('name', '4e primaire')->firstOrFail();
        $targetReady = SchoolClass::where('school_year_id', $to->id)
            ->where('level_id', $level4->id)->whereHas('divisions')->exists();
        if (! $targetReady) {
            $this->command->warn('Pas de classe 4e primaire dans 2029-2030 : génère les classes de l\'année cible d\'abord.');
        }

        // Évaluation + notes dans un terme de l'année source (pour calculer la moyenne).
        $term = $from->terms()->orderBy('id')->firstOrFail();
        $subject = Subject::firstOrFail();
        $evaluation = Evaluation::firstOrCreate(
            ['classroom_id' => $sourceClassroom->id, 'term_id' => $term->id, 'name' => 'DÉMO — Composition'],
            [
                'subject_id' => $subject->id,
                'type' => Evaluation::TYPE_CONTROLE,
                'held_on' => $from->starts_on,
                'max_value' => 20,
                'published_at' => now(),
            ],
        );

        // Cohorte : 5 admis (moyenne >= 10) + 1 redoublant (< 10).
        $cohort = [
            ['MALEKO', 'Grace', 14],
            ['KABILA', 'Joel', 15],
            ['TSHALA', 'Sarah', 13],
            ['MUKENDI', 'David', 16],
            ['NGOY', 'Esther', 12],
            ['ILUNGA', 'Patrick', 6],
        ];

        foreach ($cohort as $i => [$last, $first, $note]) {
            $matricule = sprintf('DEMO-2829-%02d', $i + 1);
            $student = Student::where('registration_number', $matricule)->first();

            if ($student === null) {
                // create() déclenche le hook qui crée l'inscription 2028-2029.
                $student = Student::factory()->create([
                    'classroom_id' => $sourceClassroom->id,
                    'enrollment_school_year_id' => $from->id,
                    'registration_number' => $matricule,
                    'first_name' => $first,
                    'last_name' => $last,
                    'enrollment_status' => 'actif',
                ]);
            }

            Grade::updateOrCreate(
                ['evaluation_id' => $evaluation->id, 'student_id' => $student->id],
                ['value' => $note, 'absent' => false],
            );
        }

        // 2028-2029 devient l'année courante ; 2029-2030 reste la cible (non courante).
        SchoolYear::query()->update(['is_current' => false]);
        $from->forceFill(['is_current' => true])->save();

        $this->command->info(
            'Démo prête : 6 élèves en 3e primaire (2028-2029, COURANTE), 5 admis + 1 redoublant, '
            .'à promouvoir en 4e primaire (2029-2030) via « Passage de classe ».'
        );
    }
}
