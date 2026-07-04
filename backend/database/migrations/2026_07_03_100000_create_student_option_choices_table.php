<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Choix d'option/filière exprimé par un élève de fin de cycle (8e CTEB)
        // pour son entrée au secondaire — collecté via le portail élève à
        // l'approche de la clôture de l'année, consommé par le passage de classe.
        Schema::create('student_option_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->foreignId('school_option_id')->constrained('school_options')->cascadeOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['student_id', 'school_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_option_choices');
    }
};
