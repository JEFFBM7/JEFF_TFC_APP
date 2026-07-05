<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fusionne les anciennes options « Scientifique Math-Physique » et
     * « Scientifique Biologie-Chimie » vers l'option unique « Scientifique »
     * (filière générale) introduite ensuite dans SchoolOptionCatalog. Sur les
     * environnements où ces classes/divisions ont déjà été générées, un
     * simple remplacement du catalogue ne suffit pas : cette migration
     * réaffecte toutes les données dépendantes puis supprime les deux
     * anciennes lignes. Idempotente (no-op si déjà fusionné ou jamais généré).
     */
    public function up(): void
    {
        $legacyNames = ['Scientifique Math-Physique', 'Scientifique Biologie-Chimie'];

        $legacyIds = DB::table('school_options')->whereIn('name', $legacyNames)->pluck('id');

        if ($legacyIds->isEmpty()) {
            return;
        }

        $mergedId = DB::table('school_options')->where('name', 'Scientifique')->value('id');

        if ($mergedId === null) {
            $mergedId = DB::table('school_options')->insertGetId([
                'name' => 'Scientifique',
                'abbreviation' => 'SCI',
                'cycle' => 'secondaire',
                'filiere' => 'generale',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Réaffecte tout ce qui dépend d'une division (classroom) donnée vers
        // une division cible, en évitant les doublons sur les tables à
        // contrainte d'unicité (teacher_assignments, classroom_subject) :
        // on y supprime la ligne legacy si l'équivalent existe déjà côté cible.
        $mergeClassroomInto = function (int $legacyClassroomId, int $targetClassroomId): void {
            foreach (['students' => 'classroom_id', 'enrollments' => 'classroom_id',
                'timetable_slots' => 'classroom_id', 'evaluations' => 'classroom_id',
                'attendances' => 'classroom_id'] as $table => $column) {
                DB::table($table)->where($column, $legacyClassroomId)->update([$column => $targetClassroomId]);
            }

            foreach (DB::table('teacher_assignments')->where('classroom_id', $legacyClassroomId)->get() as $row) {
                $exists = DB::table('teacher_assignments')
                    ->where('classroom_id', $targetClassroomId)
                    ->where('teacher_id', $row->teacher_id)
                    ->where('subject_id', $row->subject_id)
                    ->where('school_year_id', $row->school_year_id)
                    ->exists();
                $exists
                    ? DB::table('teacher_assignments')->where('id', $row->id)->delete()
                    : DB::table('teacher_assignments')->where('id', $row->id)->update(['classroom_id' => $targetClassroomId]);
            }

            foreach (DB::table('classroom_subject')->where('classroom_id', $legacyClassroomId)->get() as $row) {
                $exists = DB::table('classroom_subject')
                    ->where('classroom_id', $targetClassroomId)
                    ->where('subject_id', $row->subject_id)
                    ->exists();
                if (! $exists) {
                    DB::table('classroom_subject')->insert([
                        'classroom_id' => $targetClassroomId,
                        'subject_id' => $row->subject_id,
                        'coefficient' => $row->coefficient,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('classroom_subject')->where('classroom_id', $legacyClassroomId)->delete();

            DB::table('classrooms')->where('id', $legacyClassroomId)->delete();
        };

        foreach (DB::table('school_classes')->whereIn('school_option_id', $legacyIds)->get() as $legacyClass) {
            $targetClassId = DB::table('school_classes')
                ->where('school_year_id', $legacyClass->school_year_id)
                ->where('level_id', $legacyClass->level_id)
                ->where('school_option_id', $mergedId)
                ->value('id');

            if ($targetClassId === null) {
                // Aucune classe fusionnée pour cette année+niveau : réaffectation directe.
                DB::table('school_classes')->where('id', $legacyClass->id)->update(['school_option_id' => $mergedId]);
                DB::table('classrooms')->where('school_class_id', $legacyClass->id)->update(['school_option_id' => $mergedId]);

                continue;
            }

            // Une classe fusionnée existe déjà (ex. l'autre legacy déjà traitée) :
            // bascule chaque division vers son équivalent (même section), fusionne
            // si l'équivalent existe déjà, sinon la déplace simplement.
            foreach (DB::table('classrooms')->where('school_class_id', $legacyClass->id)->get() as $division) {
                $equivalentId = DB::table('classrooms')
                    ->where('school_class_id', $targetClassId)
                    ->where('section', $division->section)
                    ->value('id');

                if ($equivalentId === null) {
                    DB::table('classrooms')->where('id', $division->id)->update([
                        'school_class_id' => $targetClassId,
                        'school_option_id' => $mergedId,
                    ]);
                } else {
                    $mergeClassroomInto($division->id, $equivalentId);
                }
            }

            DB::table('school_classes')->where('id', $legacyClass->id)->delete();
        }

        // Sécurité : toute division qui référencerait encore une option legacy
        // sans être rattachée à une school_class (ne devrait plus arriver).
        DB::table('classrooms')->whereIn('school_option_id', $legacyIds)->update(['school_option_id' => $mergedId]);

        // Choix d'option (portail élève, 8e CTEB) déjà déposés sur une option legacy.
        DB::table('student_option_choices')->whereIn('school_option_id', $legacyIds)->update(['school_option_id' => $mergedId]);

        DB::table('school_options')->whereIn('id', $legacyIds)->delete();
    }

    public function down(): void
    {
        // Fusion de données : non réversible.
    }
};
