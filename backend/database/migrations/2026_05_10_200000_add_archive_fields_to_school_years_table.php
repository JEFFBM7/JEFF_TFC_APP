<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_current');
            $table->timestamp('archived_at')->nullable()->after('closed_at');
            $table->foreignId('archived_by_id')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropForeign(['archived_by_id']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['closed_at', 'archived_at', 'archived_by_id']);
        });
    }
};
