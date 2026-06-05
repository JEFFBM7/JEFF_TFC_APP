<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });

        $now = now();
        $mainCourseAssignments = DB::table('teacher_assignments')
            ->where('is_main', true)
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->get();

        foreach ($mainCourseAssignments as $assignment) {
            $hasPrincipalAssignment = DB::table('teacher_assignments')
                ->where('classroom_id', $assignment->classroom_id)
                ->where('school_year_id', $assignment->school_year_id)
                ->whereNull('subject_id')
                ->where('is_main', true)
                ->exists();

            if ($hasPrincipalAssignment) {
                continue;
            }

            DB::table('teacher_assignments')->insert([
                'teacher_id' => $assignment->teacher_id,
                'classroom_id' => $assignment->classroom_id,
                'subject_id' => null,
                'school_year_id' => $assignment->school_year_id,
                'is_main' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('teacher_assignments')
            ->where('is_main', true)
            ->whereNotNull('subject_id')
            ->update(['is_main' => false]);
    }

    public function down(): void
    {
        DB::table('teacher_assignments')
            ->whereNull('subject_id')
            ->delete();

        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable(false)->change();
        });
    }
};
