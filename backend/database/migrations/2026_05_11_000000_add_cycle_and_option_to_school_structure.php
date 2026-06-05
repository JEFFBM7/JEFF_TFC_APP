<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->string('cycle', 16)->default('primaire')->after('name')->index();
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->string('option', 64)->default('')->after('section');
            $table->dropUnique(['level_id', 'section']);
            $table->unique(['level_id', 'section', 'option']);
        });

        foreach ([
            ['5ème', '5e primaire', 14],
            ['4ème', '4e primaire', 13],
            ['3ème', '3e primaire', 12],
        ] as [$legacyName, $name, $order]) {
            if (DB::table('levels')->where('name', $name)->exists()) {
                DB::table('levels')->where('name', $name)->update([
                    'cycle' => 'primaire',
                    'order' => $order,
                ]);

                continue;
            }

            DB::table('levels')->where('name', $legacyName)->update([
                'name' => $name,
                'cycle' => 'primaire',
                'order' => $order,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropUnique(['level_id', 'section', 'option']);
            $table->dropColumn('option');
            $table->unique(['level_id', 'section']);
        });

        Schema::table('levels', function (Blueprint $table) {
            $table->dropIndex(['cycle']);
            $table->dropColumn('cycle');
        });
    }
};
