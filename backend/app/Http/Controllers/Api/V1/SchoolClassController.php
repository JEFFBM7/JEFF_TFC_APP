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

    public function generate(Request $request, SchoolYear $schoolYear, SchoolClassGenerationService $service): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($schoolYear->id);

        // option_ids fourni => génération sélective des options secondaires
        // (les cycles sans option sont toujours générés). Absent => tout générer.
        $optionIds = $request->has('option_ids')
            ? array_map('intval', (array) $request->input('option_ids', []))
            : null;

        $service->generateBaseClasses($schoolYear, $optionIds);

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

    /** Crée une classe (niveau + option si secondaire) avec ses premières divisions. */
    public function store(
        Request $request,
        SchoolYear $schoolYear,
        SchoolClassGenerationService $service,
    ): JsonResponse {
        SchoolYearContext::assertNotArchivedById($schoolYear->id);

        $data = $request->validate([
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'school_option_id' => ['nullable', 'integer', 'exists:school_options,id'],
            'divisions' => ['sometimes', 'integer', 'min:1', 'max:26'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $level = \App\Models\Level::query()->findOrFail($data['level_id']);
        AdminScopeContext::assertCycleAllowed($request->user(), $level->cycle);

        $option = isset($data['school_option_id'])
            ? \App\Models\SchoolOption::query()->find($data['school_option_id'])
            : null;

        if ($level->has_options && ! $option) {
            return response()->json(['message' => 'Option / filière requise pour ce niveau.'], 422);
        }

        try {
            $schoolClass = $service->createClass(
                $schoolYear,
                $level,
                $option,
                $data['divisions'] ?? 1,
                $data['capacity'] ?? 40,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $schoolClass->loadCount('divisions')->load([
            'level',
            'schoolOption',
            'divisions' => fn ($q) => $q->with(['level', 'schoolOption'])->withCount('students'),
        ]);

        return SchoolClassResource::make($schoolClass)->response()->setStatusCode(201);
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
}
