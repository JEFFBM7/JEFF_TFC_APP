<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crée une inscription (enrollment) pour chaque élève déjà rattaché à une
     * année scolaire. C'est la 1ʳᵉ ligne d'historique : elle reflète l'état
     * courant (classroom_id + année) porté jusqu'ici directement par students.
     */
    public function up(): void
    {
        DB::table('students')
            ->whereNotNull('enrollment_school_year_id')
            ->orderBy('id')
            ->chunkById(200, function ($students): void {
                $now = now();
                $rows = [];

                foreach ($students as $student) {
                    $alreadyExists = DB::table('enrollments')
                        ->where('student_id', $student->id)
                        ->where('school_year_id', $student->enrollment_school_year_id)
                        ->exists();

                    if ($alreadyExists) {
                        continue;
                    }

                    $rows[] = [
                        'student_id' => $student->id,
                        'school_year_id' => $student->enrollment_school_year_id,
                        'classroom_id' => $student->classroom_id,
                        'status' => 'actif',
                        'enrolled_on' => $student->enrolled_on,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('enrollments')->insert($rows);
                }
            });
    }

    /**
     * Réversible côté dev : on supprime les inscriptions issues du backfill
     * (celles sans batch de passage). Les inscriptions créées par un passage
     * de classe conservent un promotion_batch_id et ne sont pas touchées.
     */
    public function down(): void
    {
        DB::table('enrollments')->whereNull('promotion_batch_id')->delete();
    }
};
