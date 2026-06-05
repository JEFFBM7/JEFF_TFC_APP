<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PeriodRequest;
use App\Http\Resources\Api\V1\PeriodResource;
use App\Models\Period;
use App\Models\Term;
use App\Support\SchoolYearContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PeriodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Period::query()
            ->with('term.schoolYear')
            ->orderBy('school_year_id')
            ->orderBy('position');

        if ($request->filled('term_id')) {
            $query->where('term_id', $request->integer('term_id'));
        }

        if ($request->filled('school_year_id')) {
            $query->whereHas(
                'term',
                fn (Builder $termQuery) => $termQuery->where('school_year_id', $request->integer('school_year_id')),
            );
        }

        return PeriodResource::collection($query->paginate(50));
    }

    public function store(PeriodRequest $request): JsonResponse
    {
        $term = Term::query()->findOrFail($request->integer('term_id'));
        $this->assertWritableTerm($term);
        $this->assertWithinTermBounds($request, $term);
        $this->assertPeriodCapacity($term);

        $period = Period::query()->create($request->validated());

        return PeriodResource::make($period->load('term'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Period $period): PeriodResource
    {
        return PeriodResource::make($period->load('term'));
    }

    public function update(PeriodRequest $request, Period $period): PeriodResource
    {
        $currentTerm = $period->term()->first();
        if ($currentTerm !== null) {
            $this->assertWritableTerm($currentTerm);
        }

        $targetTerm = Term::query()->findOrFail($request->integer('term_id'));
        $this->assertWritableTerm($targetTerm);
        $this->assertWithinTermBounds($request, $targetTerm);
        $this->assertPeriodCapacity($targetTerm, $period);

        $period->update($request->validated());

        return PeriodResource::make($period->fresh()->load('term'));
    }

    public function destroy(Period $period): JsonResponse
    {
        $term = $period->term()->first();
        if ($term !== null) {
            $this->assertWritableTerm($term);
        }

        if ($period->evaluations()->exists()) {
            throw ValidationException::withMessages([
                'period' => 'Impossible de supprimer une période liée à des évaluations.',
            ]);
        }

        $period->delete();

        return response()->json(null, 204);
    }

    public function close(Period $period): JsonResponse
    {
        $term = $period->term()->first();
        if ($term !== null) {
            SchoolYearContext::assertTermNotArchived($term);
        }

        if ($period->isClosed()) {
            return response()->json([
                'message' => 'Période déjà clôturée.',
                'closed_at' => $period->closed_at,
            ], 422);
        }

        $period->update(['closed_at' => now()]);

        return response()->json([
            'message' => 'Période clôturée.',
            'closed_at' => $period->fresh()->closed_at,
        ]);
    }

    private function assertWritableTerm(Term $term): void
    {
        SchoolYearContext::assertTermNotArchived($term);

        if ($term->isClosed()) {
            throw ValidationException::withMessages([
                'term_id' => 'Impossible de modifier une période d’un '.$term->typeLabel().' clôturé.',
            ]);
        }
    }

    private function assertWithinTermBounds(PeriodRequest $request, Term $term): void
    {
        $startsOn = $request->date('starts_on');
        $endsOn = $request->date('ends_on');

        if (
            $startsOn === null
            || $endsOn === null
            || $term->starts_on === null
            || $term->ends_on === null
        ) {
            return;
        }

        if ($startsOn->lt($term->starts_on) || $endsOn->gt($term->ends_on)) {
            throw ValidationException::withMessages([
                'starts_on' => 'La période doit rester dans les bornes du trimestre.',
            ]);
        }
    }

    private function assertPeriodCapacity(Term $term, ?Period $ignore = null): void
    {
        $query = $term->periods();

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->id);
        }

        if ($query->count() >= 2) {
            throw ValidationException::withMessages([
                'term_id' => 'Un '.$term->typeLabel().' ne peut contenir que deux périodes.',
            ]);
        }
    }
}
