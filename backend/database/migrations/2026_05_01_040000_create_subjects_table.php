<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('classroom_subject', function (Blueprint $table) {
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->decimal('coefficient', 5, 2)->default(1.00);
            $table->timestamps();

            $table->primary(['classroom_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_subject');
        Schema::dropIfExists('subjects');
    }
};
