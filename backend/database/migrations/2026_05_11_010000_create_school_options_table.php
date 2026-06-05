<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_options', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('school_option_id')
                ->nullable()
                ->after('level_id')
                ->constrained('school_options')
                ->nullOnDelete();
        });

        $now = now();
        $optionNames = DB::table('classrooms')
            ->where('option', '<>', '')
            ->distinct()
            ->orderBy('option')
            ->pluck('option');

        foreach ($optionNames as $name) {
            DB::table('school_options')->insert([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $options = DB::table('school_options')->pluck('id', 'name');
        foreach ($options as $name => $id) {
            DB::table('classrooms')
                ->where('option', $name)
                ->update(['school_option_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_option_id');
        });

        Schema::dropIfExists('school_options');
    }
};
