<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SchoolYearRequest;
use App\Http\Resources\Api\V1\SchoolYearResource;
use App\Models\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SchoolYearController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SchoolYearResource::collection(
            SchoolYear::query()
                ->with('terms')
                ->orderByDesc('starts_on')
                ->paginate(20),
        );
    }

    public function store(SchoolYearRequest $request): JsonResponse
    {
        $year = DB::transaction(function () use ($request) {
            $year = SchoolYear::query()->create($request->validated());
            $this->ensureSingleCurrent($year);

            return $year->fresh();
        });

        return SchoolYearResource::make($year)
            ->response()
            ->setStatusCode(201);
    }

    public function show(SchoolYear $schoolYear): SchoolYearResource
    {
        return SchoolYearResource::make($schoolYear->load('terms'));
    }

    public function update(SchoolYearRequest $request, SchoolYear $schoolYear): SchoolYearResource
    {
        DB::transaction(function () use ($request, $schoolYear) {
            $schoolYear->update($request->validated());
            $this->ensureSingleCurrent($schoolYear->refresh());
        });

        return SchoolYearResource::make($schoolYear->fresh()->load('terms'));
    }

    public function destroy(SchoolYear $schoolYear): JsonResponse
    {
        $schoolYear->delete();

        return response()->json(null, 204);
    }

    /**
     * Une seule année courante à la fois.
     */
    protected function ensureSingleCurrent(SchoolYear $year): void
    {
        if ($year->is_current) {
            SchoolYear::query()
                ->whereKeyNot($year->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);
        }
    }
}
