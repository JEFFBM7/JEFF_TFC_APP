<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('terms')
            ->orderBy('id')
            ->get(['id', 'position', 'starts_on', 'ends_on'])
            ->each(function (object $term) use ($now): void {
                $start = CarbonImmutable::parse($term->starts_on)->startOfDay();
                $end = CarbonImmutable::parse($term->ends_on)->startOfDay();
                $days = max(0, $start->diffInDays($end));
                $firstPeriodEnd = $start->addDays((int) floor($days / 2));
                $secondPeriodStart = $firstPeriodEnd->addDay();

                if ($secondPeriodStart->gt($end)) {
                    $secondPeriodStart = $end;
                }

                $termPosition = min(3, max(1, (int) $term->position));
                $firstPosition = (($termPosition - 1) * 2) + 1;

                $periods = [
                    [
                        'position' => $firstPosition,
                        'name' => 'Période '.$firstPosition,
                        'starts_on' => $start->toDateString(),
                        'ends_on' => $firstPeriodEnd->toDateString(),
                    ],
                    [
                        'position' => $firstPosition + 1,
                        'name' => 'Période '.($firstPosition + 1),
                        'starts_on' => $secondPeriodStart->toDateString(),
                        'ends_on' => $end->toDateString(),
                    ],
                ];

                foreach ($periods as $period) {
                    DB::table('periods')->updateOrInsert(
                        ['term_id' => $term->id, 'position' => $period['position']],
                        [
                            'name' => $period['name'],
                            'starts_on' => $period['starts_on'],
                            'ends_on' => $period['ends_on'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }

                $createdPeriods = DB::table('periods')
                    ->where('term_id', $term->id)
                    ->orderBy('position')
                    ->get(['id', 'starts_on', 'ends_on', 'position']);

                DB::table('evaluations')
                    ->where('term_id', $term->id)
                    ->whereNull('period_id')
                    ->orderBy('id')
                    ->get(['id', 'held_on'])
                    ->each(function (object $evaluation) use ($createdPeriods): void {
                        $heldOn = CarbonImmutable::parse($evaluation->held_on)->toDateString();
                        $period = $createdPeriods->first(
                            fn (object $candidate): bool => $heldOn >= $candidate->starts_on && $heldOn <= $candidate->ends_on,
                        ) ?? $createdPeriods->firstWhere('position', $createdPeriods->min('position'));

                        if ($period !== null) {
                            DB::table('evaluations')
                                ->where('id', $evaluation->id)
                                ->update(['period_id' => $period->id]);
                        }
                    });
            });
    }

    public function down(): void
    {
        DB::table('evaluations')->update(['period_id' => null]);
    }
};
