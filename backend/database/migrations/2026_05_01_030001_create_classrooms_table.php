<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')
                ->constrained('levels')
                ->restrictOnDelete();
            $table->string('section', 16);
            $table->unsignedSmallInteger('capacity')->default(30);
            $table->timestamps();

            $table->unique(['level_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
