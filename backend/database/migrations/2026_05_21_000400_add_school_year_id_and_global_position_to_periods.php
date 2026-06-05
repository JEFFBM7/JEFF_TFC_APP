<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            $table->foreignId('school_year_id')
                ->nullable()
                ->after('term_id')
                ->constrained('school_years')
                ->cascadeOnDelete();
        });

        $this->renumberPeriodsGlobally();

        Schema::table('periods', function (Blueprint $table): void {
            $table->unique(['school_year_id', 'position'], 'periods_school_year_position_unique');
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            $table->dropUnique('periods_school_year_position_unique');
        });

        $this->renumberPeriodsLocally();

        Schema::table('periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('school_year_id');
        });
    }

    private function renumberPeriodsGlobally(): void
    {
        DB::table('terms')
            ->orderBy('school_year_id')
            ->orderBy('position')
            ->get(['id', 'school_year_id', 'position'])
            ->each(function (object $term): void {
                $termPosition = min(3, max(1, (int) $term->position));
                $periods = DB::table('periods')
                    ->where('term_id', $term->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($periods as $index => $period) {
                    $globalPosition = (($termPosition - 1) * 2) + $index + 1;

                    DB::table('periods')
                        ->where('id', $period->id)
                        ->update([
                            'school_year_id' => $term->school_year_id,
                            'position' => $globalPosition,
                            'name' => 'Période '.$globalPosition,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function renumberPeriodsLocally(): void
    {
        DB::table('terms')
            ->orderBy('school_year_id')
            ->orderBy('position')
            ->get(['id'])
            ->each(function (object $term): void {
                $periods = DB::table('periods')
                    ->where('term_id', $term->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($periods as $index => $period) {
                    $position = $index + 1;

                    DB::table('periods')
                        ->where('id', $period->id)
                        ->update([
                            'position' => $position,
                            'name' => 'Période '.$position,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
