<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ParentRequest;
use App\Http\Resources\Api\V1\ParentResource;
use App\Models\ParentProfile;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);
        $query = ParentProfile::query()
            ->with('user')
            ->withCount(['students' => function ($studentQuery) use ($schoolYearId): void {
                if ($schoolYearId !== null) {
                    $studentQuery->where('students.enrollment_school_year_id', $schoolYearId);
                }
            }]);

        if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
            $query->whereHas('students.classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }

        if ($schoolYearId !== null) {
            $query->whereHas('students', fn ($studentQuery) => $studentQuery
                ->where('students.enrollment_school_year_id', $schoolYearId));
        }

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                $query->whereRaw('1 = 0');
            }
            $query->whereHas('students', function ($studentQuery) use ($request, $schoolYearId): void {
                if ($schoolYearId !== null) {
                    $studentQuery->where('students.enrollment_school_year_id', $schoolYearId);
                }

                $studentQuery->whereHas('classroom.level', function ($levelQuery) use ($request): void {
                    $levelQuery->where('cycle', $request->string('cycle')->value());
                });
            });
        }

        return ParentResource::collection(
            $query
                ->orderBy('id')
                ->paginate(50),
        );
    }

    public function store(ParentRequest $request): JsonResponse
    {
        $parent = ParentProfile::query()->create($request->validated());

        return ParentResource::make($parent->load('user'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, ParentProfile $parent): ParentResource
    {
        $this->assertParentVisible($request, $parent);

        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);
        $studentYearFilter = function ($studentQuery) use ($schoolYearId, $request): void {
            if ($schoolYearId !== null) {
                $studentQuery->where('students.enrollment_school_year_id', $schoolYearId);
            }
            if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
                $studentQuery->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                    ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
            }
        };

        return ParentResource::make(
            $parent
                ->loadCount(['students' => $studentYearFilter])
                ->load([
                    'user',
                    'students' => function ($studentQuery) use ($studentYearFilter): void {
                        $studentYearFilter($studentQuery);
                        $studentQuery
                            ->with(['classroom.level', 'enrollmentSchoolYear'])
                            ->orderBy('last_name')
                            ->orderBy('first_name');
                    },
                ]),
        );
    }

    public function update(ParentRequest $request, ParentProfile $parent): ParentResource
    {
        $this->assertParentMutationAllowed($request, $parent);

        $parent->update($request->validated());

        return ParentResource::make($parent->fresh()->load('user'));
    }

    public function destroy(ParentProfile $parent): JsonResponse
    {
        $this->assertParentMutationAllowed(request(), $parent);

        \Illuminate\Support\Facades\DB::transaction(function () use ($parent) {
            $user = $parent->user;
            $parent->delete();
            if ($user) {
                $user->delete();
            }
        });

        return response()->json(null, 204);
    }

    private function assertParentMutationAllowed(Request $request, ParentProfile $parent): void
    {
        if (! $request->user()?->hasRole('admin') || AdminScopeContext::isGlobalAdmin($request->user())) {
            return;
        }

        $hasOutsideScope = $parent->students()
            ->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereNotIn('cycle', AdminScopeContext::allowedCycles($request->user())))
            ->exists();

        if ($hasOutsideScope) {
            abort(403, 'Impossible de modifier ou supprimer une personne liée à des données hors périmètre.');
        }
    }

    private function assertParentVisible(Request $request, ParentProfile $parent): void
    {
        if (! $request->user()?->hasRole('admin') || AdminScopeContext::isGlobalAdmin($request->user())) {
            return;
        }

        $hasInScopeStudent = $parent->students()
            ->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())))
            ->exists();

        if (! $hasInScopeStudent) {
            abort(403, 'Cette donnée est hors de votre périmètre administratif.');
        }
    }
}
