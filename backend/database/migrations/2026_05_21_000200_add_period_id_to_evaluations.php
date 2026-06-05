<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->foreignId('period_id')
                ->nullable()
                ->after('term_id')
                ->constrained('periods')
                ->nullOnDelete();
            $table->index(['classroom_id', 'subject_id', 'term_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropIndex(['classroom_id', 'subject_id', 'term_id', 'period_id']);
            $table->dropConstrainedForeignId('period_id');
        });
    }
};
