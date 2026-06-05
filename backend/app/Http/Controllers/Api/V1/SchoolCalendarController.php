<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Services\SchoolCalendarContextService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolCalendarController extends Controller
{
    public function context(Request $request, SchoolCalendarContextService $service): JsonResponse
    {
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);

        if ($schoolYear === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $service->build(
                $schoolYear,
                AdminScopeContext::allowedTermApplicableCycles($request->user()),
            ),
        ]);
    }

    /** Options trimestres / semestres pour le simulateur (local uniquement). */
    public function devOptions(Request $request): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        if ($schoolYear === null) {
            return response()->json(['data' => null]);
        }

        $allowed = AdminScopeContext::allowedTermApplicableCycles($request->user());
        $cycles = $allowed ?? [Term::CYCLE_PRIMAIRE, Term::CYCLE_SECONDAIRE];

        $terms = Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->whereIn('applicable_cycle', $cycles)
            ->with('periods')
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => [
                'school_year_id' => $schoolYear->id,
                'primary' => $terms
                    ->where('applicable_cycle', Term::CYCLE_PRIMAIRE)
                    ->values()
                    ->map(fn (Term $term) => $this->termOption($term))
                    ->all(),
                'secondary' => $terms
                    ->where('applicable_cycle', Term::CYCLE_SECONDAIRE)
                    ->values()
                    ->map(fn (Term $term) => $this->termOption($term))
                    ->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function termOption(Term $term): array
    {
        return [
            'id' => $term->id,
            'name' => $term->name,
            'term_type' => $term->term_type,
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
            'periods' => $term->periods->map(fn ($period) => [
                'id' => $period->id,
                'name' => $period->name,
                'position' => $period->position,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ])->all(),
        ];
    }
}
