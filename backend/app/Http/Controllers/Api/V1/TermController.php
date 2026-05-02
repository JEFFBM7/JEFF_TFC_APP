<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TermRequest;
use App\Http\Resources\Api\V1\TermResource;
use App\Models\Term;
use App\Services\TermClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TermController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Term::query()->orderBy('school_year_id')->orderBy('position');

        if ($request->filled('school_year_id')) {
            $query->where('school_year_id', $request->integer('school_year_id'));
        }

        return TermResource::collection($query->paginate(50));
    }

    public function store(TermRequest $request): JsonResponse
    {
        $term = Term::query()->create($request->validated());

        return TermResource::make($term)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Term $term): TermResource
    {
        return TermResource::make($term);
    }

    public function update(TermRequest $request, Term $term): TermResource
    {
        $term->update($request->validated());

        return TermResource::make($term->fresh());
    }

    public function destroy(Term $term): JsonResponse
    {
        $term->delete();

        return response()->json(null, 204);
    }

    /** Clôture un trimestre et envoie les bulletins aux parents (CDC §4.9 / UC-04). */
    public function close(Term $term, TermClosureService $service): JsonResponse
    {
        if ($term->isClosed()) {
            return response()->json([
                'message' => 'Trimestre déjà clôturé.',
                'closed_at' => $term->closed_at,
            ], 422);
        }

        $result = $service->close($term);

        return response()->json([
            'message' => sprintf(
                'Trimestre clôturé. %d élève(s) traité(s), %d e-mail(s) envoyé(s).',
                $result['students_notified'],
                $result['parents_notified'],
            ),
            'closed_at' => $term->fresh()->closed_at,
            'students_notified' => $result['students_notified'],
            'parents_notified' => $result['parents_notified'],
        ]);
    }
}
