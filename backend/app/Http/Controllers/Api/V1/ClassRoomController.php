<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClassRoomRequest;
use App\Http\Resources\Api\V1\ClassRoomResource;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\SchoolYear;
use App\Models\TeacherAssignment;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClassRoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $studentYearId = $schoolYear?->id;
        $query = ClassRoom::query()
            ->with(['level', 'schoolOption'])
            ->withCount(['students' => function ($query) use ($studentYearId): void {
                if ($studentYearId !== null) {
                    $query->where('enrollment_school_year_id', $studentYearId);
                }
            }]);

        AdminScopeContext::applyClassroomScope($query, $request);
        AdminScopeContext::applyTeacherClassroomScope($query, $request->user(), $studentYearId);

        if ($studentYearId !== null) {
            // Une classe rattachée à une SchoolClass est filtrée sur l'année ciblée ;
            // les classes « globales » (sans SchoolClass) restent visibles.
            // On inclut aussi les divisions ayant au moins une inscription sur l'année
            // ciblée, même si leur SchoolClass provient d'une autre année (source de
            // vérité = enrollments).
            $query->where(function ($scopeQuery) use ($studentYearId): void {
                $scopeQuery
                    ->whereDoesntHave('schoolClass')
                    ->orWhereHas(
                        'schoolClass',
                        fn ($schoolClassQuery) => $schoolClassQuery->where('school_year_id', $studentYearId),
                    )
                    ->orWhereHas(
                        'students',
                        fn ($studentQuery) => $studentQuery->where('enrollment_school_year_id', $studentYearId),
                    );
            });
        }

        if ($request->filled('level_id')) {
            AdminScopeContext::assertLevelAllowed($request->user(), $request->integer('level_id'));
            $query->where('level_id', $request->integer('level_id'));
        }

        $paginator = $query
            ->join('levels', 'levels.id', '=', 'classrooms.level_id')
            ->orderBy('levels.order')
            ->orderBy('classrooms.option')
            ->orderBy('classrooms.section')
            ->addSelect('classrooms.*')
            ->paginate(100);
        $this->attachSummaryFields($paginator->getCollection(), $schoolYear);

        return ClassRoomResource::collection($paginator);
    }

    public function store(ClassRoomRequest $request): JsonResponse
    {
        AdminScopeContext::assertLevelAllowed($request->user(), $request->integer('level_id'));

        $classroom = ClassRoom::query()->create($request->validated());

        return ClassRoomResource::make($classroom->load(['level', 'schoolOption'])->loadCount('students'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, ClassRoom $classroom): ClassRoomResource
    {
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroom);

        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $studentYearId = $schoolYear?->id;
        $classroom->load(['level', 'schoolOption'])->loadCount(['students' => function ($query) use ($studentYearId): void {
            if ($studentYearId !== null) {
                $query->where('enrollment_school_year_id', $studentYearId);
            }
        }]);
        $this->attachSummaryFields(collect([$classroom]), $schoolYear);

        return ClassRoomResource::make($classroom);
    }

    public function update(ClassRoomRequest $request, ClassRoom $classroom): ClassRoomResource
    {
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroom);
        AdminScopeContext::assertLevelAllowed($request->user(), $request->integer('level_id'));

        $classroom->update($request->validated());

        return ClassRoomResource::make($classroom->fresh()->load(['level', 'schoolOption'])->loadCount('students'));
    }

    public function destroy(ClassRoom $classroom): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroom);

        $classroom->delete();

        return response()->json(null, 204);
    }

    private function attachSummaryFields($classrooms, ?SchoolYear $schoolYear = null): void
    {
        if ($classrooms->isEmpty()) {
            return;
        }

        $classroomIds = $classrooms->pluck('id');
        $schoolYear ??= SchoolYearContext::current();

        $gradeAverages = collect();
        if ($schoolYear) {
            $termIds = $schoolYear->terms()->pluck('id');
            if ($termIds->isNotEmpty()) {
                $gradeAverages = Evaluation::query()
                    ->join('grades', 'grades.evaluation_id', '=', 'evaluations.id')
                    ->whereIn('evaluations.classroom_id', $classroomIds)
                    ->whereIn('evaluations.term_id', $termIds)
                    ->where('grades.absent', false)
                    ->whereNotNull('grades.value')
                    ->where('evaluations.max_value', '>', 0)
                    ->groupBy('evaluations.classroom_id')
                    ->selectRaw('evaluations.classroom_id, AVG((grades.value * 20.0) / evaluations.max_value) as grade_average')
                    ->pluck('grade_average', 'classroom_id');
            }
        }

        $assignments = TeacherAssignment::query()
            ->with(['teacher.user', 'subject'])
            ->whereIn('classroom_id', $classroomIds)
            ->when($schoolYear, fn ($query) => $query->where('school_year_id', $schoolYear->id))
            ->where('is_main', true)
            ->orderBy('subject_id')
            ->orderBy('teacher_id')
            ->get()
            ->groupBy('classroom_id')
            ->map(fn ($group) => $group->first());

        foreach ($classrooms as $classroom) {
            $assignment = $assignments->get($classroom->id);
            $average = $gradeAverages->get($classroom->id);

            $classroom->setAttribute('current_school_year_id', $schoolYear?->id);
            $classroom->setAttribute(
                'grade_average',
                $average !== null ? round((float) $average, 2) : null,
            );
            $classroom->setAttribute('main_teacher', $assignment?->teacher ? [
                'assignment_id' => $assignment->id,
                'id' => $assignment->teacher->id,
                'name' => $assignment->teacher->user?->name,
                'email' => $assignment->teacher->user?->email,
                'speciality' => $assignment->teacher->speciality,
                'subject_id' => $assignment->subject_id,
                'subject' => $assignment->subject?->name,
            ] : null);
        }
    }
}
