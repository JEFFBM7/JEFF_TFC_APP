<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();

            // État de l'inscription pour CETTE année.
            $table->string('status', 24)->default('actif')->index();
            // Décision de fin d'année (promu | redouble | oriente).
            $table->string('decision', 24)->nullable();
            $table->decimal('result_average', 5, 2)->nullable();

            // Chaînage du parcours d'une année à la suivante.
            $table->foreignId('previous_enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('promotion_batch_id')->nullable()->constrained('promotion_batches')->nullOnDelete();

            $table->date('enrolled_on')->nullable();
            $table->date('left_on')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Une seule inscription par élève et par année (idempotence du passage).
            $table->unique(['student_id', 'school_year_id']);
            $table->index(['school_year_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
