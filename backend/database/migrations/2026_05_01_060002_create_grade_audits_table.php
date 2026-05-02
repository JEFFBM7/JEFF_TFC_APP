<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained('grades')->cascadeOnDelete();
            $table->decimal('old_value', 5, 2)->nullable();
            $table->decimal('new_value', 5, 2)->nullable();
            $table->boolean('old_absent')->nullable();
            $table->boolean('new_absent')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('grade_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_audits');
    }
};
