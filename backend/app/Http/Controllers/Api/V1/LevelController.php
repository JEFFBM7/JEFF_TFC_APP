<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LevelRequest;
use App\Http\Resources\Api\V1\LevelResource;
use App\Models\Level;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LevelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        $query = Level::query()
            ->with(['classrooms' => fn ($query) => $this->withClassroomContext(
                AdminScopeContext::applyClassroomScope($query, $request),
                $schoolYearId,
            )]);
        AdminScopeContext::applyLevelScope($query, $request);

        return LevelResource::collection(
            $query
                ->orderBy('order')
                ->orderBy('name')
                ->paginate(50),
        );
    }

    public function store(LevelRequest $request): JsonResponse
    {
        $level = Level::query()->create($request->validated());

        return LevelResource::make($level)->response()->setStatusCode(201);
    }

    public function show(Request $request, Level $level): LevelResource
    {
        AdminScopeContext::assertCycleAllowed($request->user(), $level->cycle);

        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        return LevelResource::make(
            $level->load(['classrooms' => fn ($query) => $this->withClassroomContext(
                $query,
                $schoolYearId,
            )]),
        );
    }

    public function update(LevelRequest $request, Level $level): LevelResource
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        $level->update($request->validated());

        return LevelResource::make(
            $level->fresh()->load(['classrooms' => fn ($query) => $this->withClassroomContext(
                $query,
                $schoolYearId,
            )]),
        );
    }

    public function destroy(Level $level): JsonResponse
    {
        if ($level->classrooms()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer un niveau contenant des classes.',
            ], 422);
        }

        $level->delete();

        return response()->json(null, 204);
    }

    private function withClassroomContext($query, ?int $schoolYearId)
    {
        return $query
            // Ne renvoyer que les classes de l'année consultée (chaque année a son
            // propre jeu de classes) : sinon une même classe apparaît une fois par
            // année (doublons dans les listes déroulantes).
            ->when($schoolYearId !== null, fn ($q) => $q->whereHas(
                'schoolClass',
                fn ($scQuery) => $scQuery->where('school_year_id', $schoolYearId),
            ))
            ->with('schoolOption')
            ->orderBy('option')
            ->orderBy('section')
            ->withCount(['students' => function ($studentQuery) use ($schoolYearId): void {
                if ($schoolYearId !== null) {
                    $studentQuery->where('enrollment_school_year_id', $schoolYearId);
                }
            }]);
    }
}
