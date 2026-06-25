<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SchoolClassDivisionRequest;
use App\Http\Resources\Api\V1\ClassRoomResource;
use App\Http\Resources\Api\V1\SchoolClassResource;
use App\Models\ClassRoom;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Services\SchoolClassGenerationService;
use App\Services\SubjectCurriculumService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

class SchoolClassController extends Controller
{
    public function index(Request $request, SchoolYear $schoolYear): AnonymousResourceCollection
    {
        $query = $schoolYear->schoolClasses()
            ->with([
                'level',
                'schoolOption',
                'divisions' => fn ($q) => $q
                    ->with(['level', 'schoolOption'])
                    ->withCount('students'),
            ])
            ->withCount('divisions');

        $query->whereHas(
            'level',
            fn ($levelQuery) => AdminScopeContext::applyLevelScope($levelQuery, $request),
        );

        if ($request->filled('cycle')) {
            $query->whereHas('level', fn ($levelQuery) => $levelQuery->where('cycle', $request->string('cycle')));
        }

        return SchoolClassResource::collection(
            $query
                ->join('levels', 'levels.id', '=', 'school_classes.level_id')
                ->leftJoin('school_options', 'school_options.id', '=', 'school_classes.school_option_id')
                ->orderBy('levels.order')
                ->orderBy('school_options.name')
                ->select('school_classes.*')
                ->paginate(100),
        );
    }

    public function generate(SchoolYear $schoolYear, SchoolClassGenerationService $service): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($schoolYear->id);

        $service->generateBaseClasses($schoolYear);

        $schoolYear->schoolClasses()
            ->with(['level', 'schoolOption'])
            ->get()
            ->each(function (SchoolClass $schoolClass) use ($service): void {
                if ($schoolClass->divisions()->count() === 0) {
                    $service->addDivisions($schoolClass, 1, 40);
                }
            });

        $classes = $schoolYear->schoolClasses()
            ->with([
                'level',
                'schoolOption',
                'divisions' => fn ($q) => $q
                    ->with(['level', 'schoolOption'])
                    ->withCount('students'),
            ])
            ->withCount('divisions')
            ->join('levels', 'levels.id', '=', 'school_classes.level_id')
            ->leftJoin('school_options', 'school_options.id', '=', 'school_classes.school_option_id')
            ->orderBy('levels.order')
            ->orderBy('school_options.name')
            ->select('school_classes.*')
            ->get();

        return response()->json([
            'data' => SchoolClassResource::collection($classes)->resolve(),
            'meta' => [
                'count' => $classes->count(),
            ],
        ], 201);
    }

    public function addDivisions(
        SchoolClassDivisionRequest $request,
        SchoolClass $schoolClass,
        SchoolClassGenerationService $service,
    ): JsonResponse {
        SchoolYearContext::assertNotArchivedById($schoolClass->school_year_id);

        try {
            $divisions = $service->addDivisions(
                $schoolClass,
                $request->integer('count', 1),
                $request->integer('capacity'),
            );
            $divisions->each->load(['level', 'schoolOption', 'schoolClass']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => ClassRoomResource::collection($divisions)->resolve(),
        ], 201);
    }

    public function addNextDivision(
        SchoolClassDivisionRequest $request,
        SchoolClass $schoolClass,
        SchoolClassGenerationService $service,
    ): JsonResponse {
        SchoolYearContext::assertNotArchivedById($schoolClass->school_year_id);

        try {
            $division = $service->addNextDivision(
                $schoolClass,
                $request->integer('capacity'),
            )->load(['level', 'schoolOption', 'schoolClass']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return ClassRoomResource::make($division)
            ->response()
            ->setStatusCode(201);
    }

    public function generateCurriculum(
        SchoolYear $schoolYear,
        SubjectCurriculumService $service,
    ): JsonResponse {
        SchoolYearContext::assertNotArchivedById($schoolYear->id);

        $hasDivisions = ClassRoom::query()
            ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $schoolYear->id))
            ->exists();

        if (! $hasDivisions) {
            return response()->json([
                'message' => 'Aucune division trouvée pour cette année. Générez d’abord les classes de base.',
            ], 422);
        }

        $stats = $service->generateForSchoolYear($schoolYear, request()->user());

        if ($stats['classrooms_processed'] === 0) {
            return response()->json([
                'message' => 'Aucune division dans votre périmètre administratif pour cette année.',
            ], 422);
        }

        return response()->json([
            'data' => $stats,
            'message' => sprintf(
                'Programme scolaire appliqué à %d division(s) : %d lien(s) créé(s), %d mis à jour.',
                $stats['classrooms_processed'],
                $stats['links_created'],
                $stats['links_updated'],
            ),
        ], 201);
    }
}
