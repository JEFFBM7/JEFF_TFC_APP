<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TermRequest;
use App\Http\Resources\Api\V1\TermResource;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Services\TermClosureService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TermController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Term::query()->orderBy('school_year_id')->orderBy('position');

        SchoolYearContext::applySchoolYearColumn($query, $request);
        AdminScopeContext::applyTermScope($query, $request);

        return TermResource::collection($query->paginate(50));
    }

    public function store(TermRequest $request): JsonResponse
    {
        $schoolYear = SchoolYear::query()->find($request->integer('school_year_id'));
        if ($schoolYear && $schoolYear->isArchived()) {
            return $this->archivedReadOnlyResponse();
        }

        $term = Term::query()->create($request->validated());

        return TermResource::make($term)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Term $term): TermResource
    {
        AdminScopeContext::assertTermApplicableCycleAllowed($request->user(), $term);

        return TermResource::make($term);
    }

    public function update(TermRequest $request, Term $term): JsonResponse|TermResource
    {
        if ($this->isYearArchived($term)) {
            return $this->archivedReadOnlyResponse();
        }

        $term->update($request->validated());

        return TermResource::make($term->fresh());
    }

    public function destroy(Term $term): JsonResponse
    {
        if ($this->isYearArchived($term)) {
            return $this->archivedReadOnlyResponse();
        }

        $term->delete();

        return response()->json(null, 204);
    }

    /** Clôture un trimestre et envoie les bulletins aux parents (CDC §4.9 / UC-04). */
    public function close(Term $term, TermClosureService $service): JsonResponse
    {
        if ($this->isYearArchived($term)) {
            return $this->archivedReadOnlyResponse();
        }

        if ($term->isClosed()) {
            return response()->json([
                'message' => $term->typeLabel().' déjà clôturé.',
                'closed_at' => $term->closed_at,
            ], 422);
        }

        $result = $service->close($term);

        $messageSuffix = ($result['low_average_alerts'] ?? 0) > 0
            ? sprintf(' %d alerte(s) moyenne faible envoyée(s).', $result['low_average_alerts'])
            : '';

        return response()->json([
            'message' => sprintf(
                '%s clôturé. %d élève(s) traité(s), %d e-mail(s) envoyé(s).',
                $term->typeLabel(),
                $result['students_notified'],
                $result['parents_notified'],
            ).$messageSuffix,
            'closed_at' => $term->fresh()->closed_at,
            'students_notified' => $result['students_notified'],
            'parents_notified' => $result['parents_notified'],
            'low_average_alerts' => $result['low_average_alerts'] ?? 0,
        ]);
    }

    private function isYearArchived(Term $term): bool
    {
        $year = $term->schoolYear()->first();

        return $year !== null && $year->isArchived();
    }

    private function archivedReadOnlyResponse(): JsonResponse
    {
        return response()->json([
            'message' => "Année scolaire archivée : les données ne peuvent plus être modifiées.",
        ], 423);
    }
}
