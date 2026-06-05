<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teacher_assignments', 'is_main')) {
            Schema::table('teacher_assignments', function (Blueprint $table) {
                $table->boolean('is_main')->default(false)->after('school_year_id')->index();
            });
        }

        $assignments = DB::table('teacher_assignments')
            ->orderBy('classroom_id')
            ->orderBy('school_year_id')
            ->orderBy('subject_id')
            ->orderBy('teacher_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($assignment) => $assignment->classroom_id.'-'.$assignment->school_year_id);

        foreach ($assignments as $group) {
            $first = $group->first();
            if ($first) {
                DB::table('teacher_assignments')
                    ->where('id', $first->id)
                    ->update(['is_main' => true]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('teacher_assignments', 'is_main')) {
            return;
        }

        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
};
