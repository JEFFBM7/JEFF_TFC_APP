<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FKs that reference terms before renaming the table.
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropForeign(['term_id']);
            $table->dropIndex(['classroom_id', 'subject_id', 'term_id']);
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropForeign(['term_id']);
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->dropForeign(['term_id']);
            $table->dropUnique(['student_id', 'term_id']);
        });

        Schema::rename('terms', 'semesters');

        Schema::table('evaluations', function (Blueprint $table) {
            $table->renameColumn('term_id', 'semester_id');
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->renameColumn('term_id', 'semester_id');
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->renameColumn('term_id', 'semester_id');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->foreign('semester_id')->references('id')->on('semesters')->cascadeOnDelete();
            $table->index(['classroom_id', 'subject_id', 'semester_id']);
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->foreign('semester_id')->references('id')->on('semesters')->nullOnDelete();
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->foreign('semester_id')->references('id')->on('semesters')->cascadeOnDelete();
            $table->unique(['student_id', 'semester_id']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropForeign(['semester_id']);
            $table->dropIndex(['classroom_id', 'subject_id', 'semester_id']);
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropForeign(['semester_id']);
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->dropForeign(['semester_id']);
            $table->dropUnique(['student_id', 'semester_id']);
        });

        Schema::rename('semesters', 'terms');

        Schema::table('evaluations', function (Blueprint $table) {
            $table->renameColumn('semester_id', 'term_id');
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->renameColumn('semester_id', 'term_id');
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->renameColumn('semester_id', 'term_id');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->index(['classroom_id', 'subject_id', 'term_id']);
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->foreign('term_id')->references('id')->on('terms')->nullOnDelete();
        });
        Schema::table('report_card_appreciations', function (Blueprint $table) {
            $table->foreign('term_id')->references('id')->on('terms')->cascadeOnDelete();
            $table->unique(['student_id', 'term_id']);
        });
    }
};
