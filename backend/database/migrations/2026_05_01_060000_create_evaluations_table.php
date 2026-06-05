<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('name', 128);
            $table->string('type', 32); // devoir, controle, examen
            $table->date('held_on');
            $table->decimal('max_value', 5, 2)->default(20);
            $table->timestamps();

            $table->index(['classroom_id', 'subject_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
