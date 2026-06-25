<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->foreignId('to_school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->foreignId('run_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('promoted_count')->default(0);
            $table->unsignedInteger('repeated_count')->default(0);
            $table->unsignedInteger('graduated_count')->default(0);
            $table->string('status', 24)->default('committed')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_school_year_id', 'to_school_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_batches');
    }
};
