<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('classrooms', 'capacity')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('classrooms', 'capacity')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->default(30)->after('section');
        });
    }
};
