<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
