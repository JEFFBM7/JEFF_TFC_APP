<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role')->index();
        });

        $disabledStudentUserIds = DB::table('users')
            ->join('students', 'students.user_id', '=', 'users.id')
            ->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')
            ->leftJoin('levels', 'levels.id', '=', 'classrooms.level_id')
            ->where('users.role', UserRole::Eleve->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('levels.id')
                    ->orWhereNotIn('levels.cycle', ['cteb', 'secondaire']);
            })
            ->pluck('users.id');

        if ($disabledStudentUserIds->isEmpty()) {
            return;
        }

        DB::table('users')
            ->whereIn('id', $disabledStudentUserIds)
            ->update(['is_active' => false]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $disabledStudentUserIds)
            ->delete();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
