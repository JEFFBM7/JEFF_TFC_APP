<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student', function (Blueprint $table) {
            $table->foreignId('parent_profile_id')->constrained('parent_profiles')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('relation', 32)->default('tuteur'); // pere, mere, tuteur
            $table->timestamps();

            $table->primary(['parent_profile_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');
    }
};
