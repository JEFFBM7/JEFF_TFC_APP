<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('classroom_id')
                ->nullable()
                ->constrained('classrooms')
                ->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('registration_number', 32)->nullable()->unique();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['classroom_id', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
