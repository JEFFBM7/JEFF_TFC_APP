<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            if (! Schema::hasColumn('subjects', 'code')) {
                $table->string('code', 32)->nullable()->unique()->after('name');
            }
            if (! Schema::hasColumn('subjects', 'default_coefficient')) {
                $table->decimal('default_coefficient', 5, 2)->default(1.00)->after('description');
            }
            if (! Schema::hasColumn('subjects', 'evaluation_type')) {
                $table->string('evaluation_type', 32)->default('sur_20')->after('default_coefficient');
            }
            if (! Schema::hasColumn('subjects', 'status')) {
                $table->string('status', 16)->default('actif')->after('evaluation_type')->index();
            }
        });

        Schema::table('teacher_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_assignments', 'term_id')) {
                $table->foreignId('term_id')
                    ->nullable()
                    ->after('school_year_id')
                    ->constrained('terms')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('teacher_assignments', 'weekly_hours')) {
                $table->decimal('weekly_hours', 5, 2)->nullable()->after('term_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('teacher_assignments', 'weekly_hours')) {
                $table->dropColumn('weekly_hours');
            }
            if (Schema::hasColumn('teacher_assignments', 'term_id')) {
                $table->dropConstrainedForeignId('term_id');
            }
        });

        Schema::table('subjects', function (Blueprint $table) {
            foreach (['status', 'evaluation_type', 'default_coefficient', 'code'] as $column) {
                if (Schema::hasColumn('subjects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
