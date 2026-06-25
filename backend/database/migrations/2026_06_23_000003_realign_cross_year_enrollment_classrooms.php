<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Répare les inscriptions dont la classe appartient à une AUTRE année que
     * l'année de l'inscription (données de seed incohérentes recopiées par le
     * backfill). On réaligne chaque inscription vers la classe équivalente
     * (même niveau + option + section) de sa propre année ; à défaut, la classe
     * est mise à null (élève inscrit mais non affecté). Le cache students est
     * resynchronisé pour l'année courante.
     */
    public function up(): void
    {
        $currentYearId = DB::table('school_years')->where('is_current', true)->value('id');

        DB::table('enrollments')
            ->whereNotNull('classroom_id')
            ->orderBy('id')
            ->get()
            ->each(function ($enrollment) use ($currentYearId): void {
                $classroom = DB::table('classrooms')->where('id', $enrollment->classroom_id)->first();

                if ($classroom === null || $classroom->school_class_id === null || $classroom->level_id === null) {
                    return;
                }

                $classYear = DB::table('school_classes')
                    ->where('id', $classroom->school_class_id)
                    ->value('school_year_id');

                // Cohérent : rien à faire.
                if ($classYear === null || (int) $classYear === (int) $enrollment->school_year_id) {
                    return;
                }

                $equivalentId = DB::table('classrooms')
                    ->join('school_classes', 'school_classes.id', '=', 'classrooms.school_class_id')
                    ->where('school_classes.school_year_id', $enrollment->school_year_id)
                    ->where('classrooms.level_id', $classroom->level_id)
                    ->where('classrooms.section', $classroom->section)
                    ->when(
                        $classroom->school_option_id !== null,
                        fn ($query) => $query->where('classrooms.school_option_id', $classroom->school_option_id),
                        fn ($query) => $query->whereNull('classrooms.school_option_id'),
                    )
                    ->value('classrooms.id');

                DB::table('enrollments')
                    ->where('id', $enrollment->id)
                    ->update(['classroom_id' => $equivalentId]);

                if ($currentYearId !== null && (int) $enrollment->school_year_id === (int) $currentYearId) {
                    DB::table('students')
                        ->where('id', $enrollment->student_id)
                        ->update(['classroom_id' => $equivalentId]);
                }
            });
    }

    public function down(): void
    {
        // Réparation de données : non réversible.
    }
};
