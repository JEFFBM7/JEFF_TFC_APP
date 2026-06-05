<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $levelsHasAbbreviation = Schema::hasColumn('levels', 'abbreviation');
        $levelsHasOptions = Schema::hasColumn('levels', 'has_options');
        $optionsHasAbbreviation = Schema::hasColumn('school_options', 'abbreviation');
        $optionsHasCycle = Schema::hasColumn('school_options', 'cycle');
        $classroomsHasSchoolClass = Schema::hasColumn('classrooms', 'school_class_id');
        $classroomsHasCapacity = Schema::hasColumn('classrooms', 'capacity');
        $classroomsHasActive = Schema::hasColumn('classrooms', 'active');

        Schema::table('levels', function (Blueprint $table) use ($levelsHasAbbreviation, $levelsHasOptions) {
            if (! $levelsHasAbbreviation) {
                $table->string('abbreviation', 16)->nullable()->after('name');
            }
            if (! $levelsHasOptions) {
                $table->boolean('has_options')->default(false)->after('order')->index();
            }
        });

        Schema::table('school_options', function (Blueprint $table) use ($optionsHasAbbreviation, $optionsHasCycle) {
            if (! $optionsHasAbbreviation) {
                $table->string('abbreviation', 20)->nullable()->after('name');
            }
            if (! $optionsHasCycle) {
                $table->string('cycle', 16)->nullable()->after('abbreviation')->index();
            }
        });

        if (! Schema::hasTable('school_classes')) {
            Schema::create('school_classes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();
                $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
                $table->foreignId('school_option_id')->nullable()->constrained('school_options')->nullOnDelete();
                $table->string('name', 100);
                $table->boolean('active')->default(true)->index();
                $table->timestamps();

                $table->index(['school_year_id', 'active']);
                $table->unique(['school_year_id', 'level_id', 'school_option_id'], 'school_classes_unique_structure');
            });
        }

        Schema::table('classrooms', function (Blueprint $table) use ($classroomsHasSchoolClass, $classroomsHasCapacity, $classroomsHasActive) {
            $table->dropUnique(['level_id', 'section', 'option']);
            if (! $classroomsHasSchoolClass) {
                $table->foreignId('school_class_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('school_classes')
                    ->nullOnDelete();
            }
            if (! $classroomsHasCapacity) {
                $table->unsignedSmallInteger('capacity')->default(40)->after('option');
            }
            if (! $classroomsHasActive) {
                $table->boolean('active')->default(true)->after('capacity')->index();
            }
            $table->index(['level_id', 'section', 'option']);
            $table->unique(['school_class_id', 'section'], 'classrooms_school_class_section_unique');
        });

        $this->backfillLevelMetadata();
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropUnique('classrooms_school_class_section_unique');
            $table->dropIndex(['level_id', 'section', 'option']);
            if (Schema::hasColumn('classrooms', 'school_class_id')) {
                $table->dropConstrainedForeignId('school_class_id');
            }
            if (Schema::hasColumn('classrooms', 'active')) {
                $table->dropColumn('active');
            }
            if (Schema::hasColumn('classrooms', 'capacity')) {
                $table->dropColumn('capacity');
            }
            $table->unique(['level_id', 'section', 'option']);
        });

        Schema::dropIfExists('school_classes');

        Schema::table('school_options', function (Blueprint $table) {
            if (Schema::hasColumn('school_options', 'cycle')) {
                $table->dropIndex(['cycle']);
                $table->dropColumn('cycle');
            }
            if (Schema::hasColumn('school_options', 'abbreviation')) {
                $table->dropColumn('abbreviation');
            }
        });

        Schema::table('levels', function (Blueprint $table) {
            if (Schema::hasColumn('levels', 'has_options')) {
                $table->dropIndex(['has_options']);
                $table->dropColumn('has_options');
            }
            if (Schema::hasColumn('levels', 'abbreviation')) {
                $table->dropColumn('abbreviation');
            }
        });
    }

    private function backfillLevelMetadata(): void
    {
        foreach ([
            ['1ère maternelle', 'M1', false],
            ['2e maternelle', 'M2', false],
            ['2ème Maternelle', 'M2', false],
            ['3e maternelle', 'M3', false],
            ['3ème Maternelle', 'M3', false],
            ['1ère primaire', '1P', false],
            ['2e primaire', '2P', false],
            ['3e primaire', '3P', false],
            ['4e primaire', '4P', false],
            ['5e primaire', '5P', false],
            ['6e primaire', '6P', false],
            ['7e CTEB', '7EB', false],
            ['8e CTEB', '8EB', false],
            ['1ère secondaire', '9S', true],
            ['2e secondaire', '10S', true],
            ['3e secondaire', '11S', true],
            ['4e secondaire', '12S', true],
        ] as [$name, $abbreviation, $hasOptions]) {
            DB::table('levels')->where('name', $name)->update([
                'abbreviation' => $abbreviation,
                'has_options' => $hasOptions,
            ]);
        }
    }
};
