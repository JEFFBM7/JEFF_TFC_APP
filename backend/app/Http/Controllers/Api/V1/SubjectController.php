<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignSubjectTeacherRequest;
use App\Http\Requests\Api\V1\ClassroomSubjectRequest;
use App\Http\Requests\Api\V1\SubjectRequest;
use App\Models\Teacher;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Services\TeacherAssignmentSyncService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use App\Support\TeacherSpecialityMatcher;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        if ($schoolYearId !== null) {
            return $this->indexForSchoolYear($request, $schoolYearId);
        }

        $query = Subject::query()->with([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]);

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                $query->whereRaw('1 = 0');
            }
            $query->whereHas('classrooms.level', function ($levelQuery) use ($request): void {
                $levelQuery->where('cycle', $request->string('cycle')->value());
            });
            if ($request->filled('classroom_id')) {
                AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));
                $query->whereHas('classrooms', fn ($classroomQuery) => $classroomQuery
                    ->whereKey($request->integer('classroom_id')));
            }
        } elseif ($request->filled('classroom_id')) {
            AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));
            $query->whereHas('classrooms', fn ($classroomQuery) => $classroomQuery
                ->whereKey($request->integer('classroom_id')));
        } elseif ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
            $query->whereHas('classrooms.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }

        return SubjectResource::collection($query->orderBy('name')->paginate(100));
    }

    public function store(SubjectRequest $request): JsonResponse
    {
        $subject = DB::transaction(function () use ($request): Subject {
            $data = $request->validated();
            if (! empty($data['classroom_id'])) {
                AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);
            }
            $subject = Subject::query()->create($this->subjectData($data));
            $this->syncCourseOrganization($subject, $data);

            return $subject;
        });

        return SubjectResource::make($subject->load([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]))->response()->setStatusCode(201);
    }

    public function show(Subject $subject): SubjectResource
    {
        $this->assertSubjectAllowed($subject);

        return SubjectResource::make($subject->load([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]));
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        DB::transaction(function () use ($request, $subject): void {
            $data = $request->validated();
            if (! empty($data['classroom_id'])) {
                AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);
            }
            $this->assertSubjectAllowed($subject);
            $subject->update($this->subjectData($data));
            $this->syncCourseOrganization($subject, $data);
        });

        return SubjectResource::make($subject->fresh()->load([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]));
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $this->assertSubjectAllowed($subject);

        $subject->delete();

        return response()->json(null, 204);
    }

    public function assignTeacher(AssignSubjectTeacherRequest $request, Subject $subject): SubjectResource
    {
        $this->assertSubjectAllowed($subject);
        SchoolYearContext::assertNotArchivedById($request->integer('school_year_id'));

        $data = $request->validated();
        AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);

        $classroom = ClassRoom::query()->findOrFail($data['classroom_id']);
        if (! $classroom->subjects()->whereKey($subject->id)->exists()) {
            throw ValidationException::withMessages([
                'classroom_id' => ['Ce cours n\'est pas rattaché à la classe sélectionnée.'],
            ]);
        }

        $classroom->loadMissing('level');

        if (TeacherSpecialityMatcher::isPrimaryOrMaternelClassroom($classroom)) {
            throw ValidationException::withMessages([
                'classroom_id' => [
                    'Au primaire et en maternelle, assignez l\'enseignant titulaire à la classe depuis Enseignants : tous les cours de la classe seront rattachés automatiquement.',
                ],
            ]);
        }

        $teacher = Teacher::query()->with('user')->findOrFail($data['teacher_id']);
        AdminScopeContext::assertTeacherAllowed($request->user(), $teacher);
        if (! TeacherSpecialityMatcher::canAssignToCourse($teacher, $subject, $classroom)) {
            $message = TeacherSpecialityMatcher::isPrimaryOrMaternelClassroom($classroom)
                ? 'Seuls les enseignants du primaire / maternel peuvent être assignés à ce cours.'
                : 'La spécialité de l\'enseignant doit correspondre au cours (« '.$subject->name.' »).';

            throw ValidationException::withMessages([
                'teacher_id' => [$message],
            ]);
        }

        $this->syncCourseOrganization($subject, [
            ...$data,
            'default_coefficient' => $subject->default_coefficient,
        ]);

        return SubjectResource::make($subject->fresh()->load([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments' => fn ($query) => $query
                ->where('school_year_id', $data['school_year_id'])
                ->where('classroom_id', $data['classroom_id']),
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]));
    }

    public function unassignTeacher(Request $request, Subject $subject): SubjectResource
    {
        $this->assertSubjectAllowed($subject);

        $validated = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
        ]);

        SchoolYearContext::assertNotArchivedById((int) $validated['school_year_id']);
        AdminScopeContext::assertClassroomAllowed($request->user(), (int) $validated['classroom_id']);

        TeacherAssignment::query()
            ->where('subject_id', $subject->id)
            ->where('classroom_id', $validated['classroom_id'])
            ->where('school_year_id', $validated['school_year_id'])
            ->delete();

        return SubjectResource::make($subject->fresh()->load([
            'classrooms.level',
            'classrooms.schoolOption',
            'assignments.teacher.user',
            'assignments.classroom.level',
            'assignments.schoolYear',
            'assignments.term',
        ]));
    }

    // ─── Coefficient per classroom ───────────────────────────────────────────

    /** List subjects attached to a classroom with their coefficients. */
    public function classroomSubjects(ClassRoom $classroom): AnonymousResourceCollection
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroom);

        return SubjectResource::collection(
            $classroom->subjects()->orderBy('name')->get(),
        );
    }

    /** Attach or update a subject + coefficient on a classroom. */
    public function syncClassroomSubject(ClassroomSubjectRequest $request, ClassRoom $classroom): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroom);

        $data = $request->validated();
        $classroom->subjects()->syncWithoutDetaching([
            $data['subject_id'] => ['coefficient' => $data['coefficient'] ?? 1.0],
        ]);

        $subjects = $classroom->subjects()->orderBy('name')->get();

        return SubjectResource::collection($subjects)->response()->setStatusCode(200);
    }

    /** Remove a subject from a classroom. */
    public function detachClassroomSubject(ClassRoom $classroom, Subject $subject): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroom);

        $classroom->subjects()->detach($subject->id);

        return response()->json(null, 204);
    }

    private function indexForSchoolYear(Request $request, int $schoolYearId): AnonymousResourceCollection
    {
        $classroomQuery = ClassRoom::query()
            ->where(function ($query) use ($schoolYearId): void {
                $query->whereHas('schoolClass', fn ($schoolClassQuery) => $schoolClassQuery
                    ->where('school_year_id', $schoolYearId))
                    ->orWhereIn('id', TeacherAssignment::query()
                        ->where('school_year_id', $schoolYearId)
                        ->select('classroom_id'));
            })
            ->with(['level', 'schoolOption', 'subjects' => fn ($query) => $query->orderBy('name')]);

        AdminScopeContext::applyClassroomScope($classroomQuery, $request);
        AdminScopeContext::applyTeacherClassroomScope($classroomQuery, $request->user(), $schoolYearId);

        if ($request->filled('classroom_id')) {
            AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));
            $classroomQuery->whereKey($request->integer('classroom_id'));
        }

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                return SubjectResource::collection(collect());
            }

            $classroomQuery->whereHas('level', fn ($levelQuery) => $levelQuery
                ->where('cycle', $request->string('cycle')->value()));
        }

        $classrooms = $classroomQuery->get();
        $classroomIds = $classrooms->pluck('id');

        $assignmentSync = app(TeacherAssignmentSyncService::class);

        foreach ($classrooms as $classroom) {
            $assignmentSync->refreshClassroomTitularSubjects($classroom->id, $schoolYearId);
        }

        $assignments = TeacherAssignment::query()
            ->where('school_year_id', $schoolYearId)
            ->whereIn('classroom_id', $classroomIds)
            ->with(['teacher.user', 'classroom.level', 'schoolYear', 'term'])
            ->get()
            ->groupBy(fn (TeacherAssignment $assignment) => ($assignment->subject_id ?? '').'-'.$assignment->classroom_id);

        $rows = $this->buildSchoolYearCourseRows($classrooms, $assignments);
        $page = max(1, $request->integer('page', 1));
        $perPage = min(500, max(1, $request->integer('per_page', 500)));

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return SubjectResource::collection($paginator);
    }

    /**
     * @param  Collection<int, ClassRoom>  $classrooms
     * @param  Collection<string, Collection<int, TeacherAssignment>>  $assignments
     * @return Collection<int, Subject>
     */
    private function buildSchoolYearCourseRows(Collection $classrooms, Collection $assignments): Collection
    {
        $rows = collect();

        foreach ($classrooms as $classroom) {
            $useTitularFallback = in_array(
                $classroom->level?->cycle,
                [Level::CYCLE_PRIMAIRE, Level::CYCLE_MATERNEL],
                true,
            );

            foreach ($classroom->subjects as $subject) {
                $assignment = $assignments->get($subject->id.'-'.$classroom->id)?->first();

                if ($assignment === null && $useTitularFallback) {
                    $assignment = $assignments->get('-'.$classroom->id)?->first();
                }

                $row = clone $subject;
                $row->setRelation('classrooms', collect([$classroom]));
                $row->setRelation('assignments', $assignment ? collect([$assignment]) : collect());

                $rows->push($row);
            }
        }

        return $rows->sortBy(function (Subject $subject): string {
            $classroom = $subject->classrooms->first();

            return sprintf(
                '%05d-%s-%s',
                $classroom?->level?->order ?? 9999,
                $classroom?->full_name ?? '',
                $subject->name,
            );
        })->values();
    }

    private function assertSubjectAllowed(Subject $subject): void
    {
        $user = request()->user();
        if (! $user?->hasRole('admin') || AdminScopeContext::isGlobalAdmin($user)) {
            return;
        }

        $hasOutsideScope = $subject->classrooms()
            ->whereHas('level', fn ($levelQuery) => $levelQuery
                ->whereNotIn('cycle', AdminScopeContext::allowedCycles($user)))
            ->exists();

        if ($hasOutsideScope) {
            abort(403, 'Impossible de gérer un cours lié à des classes hors périmètre.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function subjectData(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'default_coefficient' => $data['default_coefficient'] ?? 1.00,
            'evaluation_type' => $data['evaluation_type'] ?? 'sur_20',
            'status' => $data['status'] ?? 'actif',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCourseOrganization(Subject $subject, array $data): void
    {
        if (empty($data['classroom_id'])) {
            return;
        }

        $subject->classrooms()->syncWithoutDetaching([
            $data['classroom_id'] => [
                'coefficient' => $data['default_coefficient'] ?? 1.00,
            ],
        ]);

        if (empty($data['teacher_id']) || empty($data['school_year_id'])) {
            return;
        }

        $assignment = TeacherAssignment::query()
            ->where('subject_id', $subject->id)
            ->where('classroom_id', $data['classroom_id'])
            ->where('school_year_id', $data['school_year_id'])
            ->first();

        $payload = [
            'teacher_id' => $data['teacher_id'],
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $subject->id,
            'school_year_id' => $data['school_year_id'],
            'term_id' => $data['term_id'] ?? null,
            'weekly_hours' => $data['weekly_hours'] ?? null,
            'is_main' => false,
        ];

        if ($assignment) {
            $assignment->update($payload);

            return;
        }

        TeacherAssignment::query()->create($payload);
    }
}
