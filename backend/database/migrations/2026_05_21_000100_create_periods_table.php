<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->string('name', 64);
            $table->unsignedTinyInteger('position');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'position']);
            $table->unique(['term_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
