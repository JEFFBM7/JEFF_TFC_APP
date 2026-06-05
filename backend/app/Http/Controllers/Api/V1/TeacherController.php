<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignTeacherClassroomRequest;
use App\Http\Requests\Api\V1\AssignmentRequest;
use App\Http\Requests\Api\V1\TeacherRequest;
use App\Http\Resources\Api\V1\AssignmentResource;
use App\Http\Resources\Api\V1\TeacherResource;
use App\Models\ClassRoom;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\TeacherAssignmentSyncService;
use App\Services\TeacherRegistrationNumberService;
use App\Models\Level;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use App\Support\TeacherSpecialityMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherController extends Controller
{
    private const DEFAULT_PASSWORD = 'Malunga2026';

    /** @var list<string> */
    private const TEACHER_FIELDS = [
        'teacher_type',
        'registration_number',
        'gender',
        'birth_date',
        'address',
        'grade',
        'contract_type',
        'hired_on',
        'speciality',
        'phone',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);
        $query = Teacher::query()->with([
            'user',
            'assignments' => function ($assignmentQuery) use ($schoolYearId): void {
                if ($schoolYearId !== null) {
                    $assignmentQuery->where('school_year_id', $schoolYearId);
                }
                $assignmentQuery->with(['classroom.level', 'classroom.subjects', 'subject']);
            },
        ]);

        if ($request->filled('for_subject_id')) {
            $subject = \App\Models\Subject::query()->findOrFail($request->integer('for_subject_id'));

            if ($request->filled('for_classroom_id')) {
                $classroom = ClassRoom::query()->with('level')->findOrFail($request->integer('for_classroom_id'));

                if (TeacherSpecialityMatcher::isPrimaryOrMaternelClassroom($classroom)) {
                    $query->where('teacher_type', Teacher::TYPE_PRIMAIRE);
                } else {
                    $normalized = TeacherSpecialityMatcher::normalize($subject->name);
                    $query->where('teacher_type', Teacher::TYPE_SECONDAIRE)
                        ->whereRaw('LOWER(TRIM(speciality)) = ?', [$normalized]);
                }
            } else {
                $normalized = TeacherSpecialityMatcher::normalize($subject->name);
                $expectedType = AdminScopeContext::expectedTeacherTypeForScope($request->user()?->admin_scope);

                $query->where(function ($eligible) use ($normalized, $expectedType): void {
                    if ($expectedType === Teacher::TYPE_PRIMAIRE) {
                        $eligible->where('teacher_type', Teacher::TYPE_PRIMAIRE);

                        return;
                    }

                    if ($expectedType === Teacher::TYPE_SECONDAIRE) {
                        $eligible->where('teacher_type', Teacher::TYPE_SECONDAIRE)
                            ->whereRaw('LOWER(TRIM(speciality)) = ?', [$normalized]);

                        return;
                    }

                    $eligible->where('teacher_type', Teacher::TYPE_PRIMAIRE)
                        ->orWhere(fn ($secondary) => $secondary
                            ->where('teacher_type', Teacher::TYPE_SECONDAIRE)
                            ->whereRaw('LOWER(TRIM(speciality)) = ?', [$normalized]));
                });
            }
        }

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                $query->whereRaw('1 = 0');
            } else {
                $cycle = $request->string('cycle')->value();
                $expectedType = in_array($cycle, [Level::CYCLE_MATERNEL, Level::CYCLE_PRIMAIRE], true)
                    ? Teacher::TYPE_PRIMAIRE
                    : Teacher::TYPE_SECONDAIRE;

                $query->where(function ($outer) use ($cycle, $expectedType, $request): void {
                    $outer->whereHas('assignments', function ($assignmentQuery) use ($cycle, $request): void {
                        $assignmentQuery->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                            ->where('cycle', $cycle));
                        if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
                            $assignmentQuery->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
                        }
                    })->orWhere(function ($unassigned) use ($expectedType): void {
                        $unassigned->where('teacher_type', $expectedType)
                            ->whereDoesntHave('assignments');
                    });
                });
            }
        }

        AdminScopeContext::applyTeacherScope($query, $request->user());

        return TeacherResource::collection(
            $query->orderBy('id')->paginate(50),
        );
    }

    public function store(TeacherRequest $request, TeacherRegistrationNumberService $registrationNumbers): JsonResponse
    {
        $this->assertTeacherTypeAllowed($request, $request->input('teacher_type'));

        $teacher = DB::transaction(function () use ($request, $registrationNumbers): Teacher {
            $validated = $request->validated();

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'] ?? self::DEFAULT_PASSWORD,
                'role' => UserRole::Enseignant,
            ]);

            $teacher = Teacher::query()->create([
                ...$this->teacherAttributes($validated),
                'user_id' => $user->id,
            ]);

            return $registrationNumbers->assignIfMissing($teacher);
        });

        return TeacherResource::make(
            $teacher->load(['user', 'assignments.classroom', 'assignments.subject']),
        )->response()->setStatusCode(201);
    }

    public function show(Request $request, Teacher $teacher): TeacherResource
    {
        $this->assertTeacherVisible($request, $teacher);
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        if ($schoolYearId !== null) {
            $teacher->load([
                'assignments' => fn ($query) => $query->where('school_year_id', $schoolYearId),
            ]);

            foreach ($teacher->assignments->where('is_main', true)->whereNull('subject_id') as $mainAssignment) {
                app(TeacherAssignmentSyncService::class)
                    ->refreshClassroomTitularSubjects($mainAssignment->classroom_id, $schoolYearId);
            }
        }

        return TeacherResource::make($teacher->load([
            'user',
            'assignments' => fn ($query) => $schoolYearId
                ? $query->where('school_year_id', $schoolYearId)
                : $query,
            'assignments.classroom.subjects',
            'assignments.classroom',
            'assignments.subject',
        ]));
    }

    public function update(TeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $this->assertTeacherMutationAllowed($request, $teacher);

        AdminScopeContext::assertTeacherAllowed($request->user(), $teacher);

        DB::transaction(function () use ($request, $teacher): void {
            $validated = $request->validated();
            $this->assertTeacherTypeAllowed($request, $validated['teacher_type'] ?? $teacher->teacher_type);
            $user = $teacher->user;

            if ($user !== null) {
                $userData = [];

                if (array_key_exists('name', $validated)) {
                    $userData['name'] = $validated['name'];
                }
                if (array_key_exists('email', $validated)) {
                    $userData['email'] = $validated['email'];
                }
                if (! empty($validated['password'])) {
                    $userData['password'] = $validated['password'];
                }

                if ($userData !== []) {
                    $user->update($userData);
                }
            }

            $teacher->update($this->teacherAttributes($validated));
        });

        return TeacherResource::make($teacher->fresh()->load([
            'user',
            'assignments.classroom',
            'assignments.subject',
        ]));
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $this->assertTeacherMutationAllowed(request(), $teacher);

        DB::transaction(function () use ($teacher) {
            $user = $teacher->user;
            $teacher->delete();
            if ($user) {
                $user->delete();
            }
        });

        return response()->json(null, 204);
    }

    public function assignClassroom(
        AssignTeacherClassroomRequest $request,
        Teacher $teacher,
        TeacherAssignmentSyncService $assignmentSync,
    ): TeacherResource {
        $this->assertTeacherMutationAllowed($request, $teacher);

        if ($teacher->teacher_type !== Teacher::TYPE_PRIMAIRE) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'L\'affectation par classe concerne uniquement les enseignants du primaire et du maternel.',
                ],
            ]);
        }

        $validated = $request->validated();
        $schoolYearId = $this->resolveSchoolYearId($request, $validated);

        $assignmentSync->sync($teacher, [
            'teacher_type' => Teacher::TYPE_PRIMAIRE,
            'classroom_id' => (int) $validated['classroom_id'],
        ], $schoolYearId, $request->user());

        return TeacherResource::make($teacher->fresh()->load([
            'user',
            'assignments' => fn ($query) => $query->where('school_year_id', $schoolYearId),
            'assignments.classroom',
            'assignments.subject',
        ]));
    }

    public function unassignClassroom(Request $request, Teacher $teacher): TeacherResource
    {
        $this->assertTeacherMutationAllowed($request, $teacher);

        if ($teacher->teacher_type !== Teacher::TYPE_PRIMAIRE) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'Seuls les enseignants du primaire et du maternel peuvent être désaffectés par classe.',
                ],
            ]);
        }

        $validated = $request->validate([
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
        ]);

        $schoolYearId = $this->resolveSchoolYearId($request, $validated);

        TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->where('school_year_id', $schoolYearId)
            ->delete();

        return TeacherResource::make($teacher->fresh()->load([
            'user',
            'assignments' => fn ($query) => $query->where('school_year_id', $schoolYearId),
            'assignments.classroom',
            'assignments.subject',
        ]));
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function assignments(Request $request): AnonymousResourceCollection
    {
        $query = TeacherAssignment::query()
            ->with(['teacher.user', 'classroom.level', 'subject', 'term']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }
        if ($request->filled('classroom_id')) {
            AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));
            $query->where('classroom_id', $request->integer('classroom_id'));
        }
        if ($request->user()?->hasRole('admin')) {
            $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }
        SchoolYearContext::applySchoolYearColumn($query, $request);

        return AssignmentResource::collection($query->paginate(100));
    }

    public function storeAssignment(AssignmentRequest $request): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($request->integer('school_year_id'));
        AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));

        $assignment = DB::transaction(function () use ($request) {
            $assignment = TeacherAssignment::query()->create($request->validated());
            $this->ensureSingleMainAssignment($assignment);

            return $assignment;
        });

        return AssignmentResource::make(
            $assignment->load(['teacher.user', 'classroom.level', 'subject', 'term']),
        )->response()->setStatusCode(201);
    }

    public function updateAssignment(AssignmentRequest $request, TeacherAssignment $assignment): AssignmentResource
    {
        SchoolYearContext::assertNotArchivedById($assignment->school_year_id);
        SchoolYearContext::assertNotArchivedById($request->integer('school_year_id'));
        AdminScopeContext::assertClassroomAllowed($request->user(), $assignment->classroom_id);
        AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));

        $assignment = DB::transaction(function () use ($request, $assignment) {
            $assignment->update($request->validated());
            $this->ensureSingleMainAssignment($assignment);

            return $assignment->fresh();
        });

        return AssignmentResource::make(
            $assignment->load(['teacher.user', 'classroom.level', 'subject', 'term']),
        );
    }

    public function destroyAssignment(TeacherAssignment $assignment): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($assignment->school_year_id);
        AdminScopeContext::assertClassroomAllowed(request()->user(), $assignment->classroom_id);

        $assignment->delete();

        return response()->json(null, 204);
    }

    private function assertTeacherMutationAllowed(Request $request, Teacher $teacher): void
    {
        AdminScopeContext::assertTeacherAllowed($request->user(), $teacher);
    }

    private function assertTeacherVisible(Request $request, Teacher $teacher): void
    {
        AdminScopeContext::assertTeacherAllowed($request->user(), $teacher);
    }

    private function assertTeacherTypeAllowed(Request $request, ?string $teacherType): void
    {
        if ($teacherType === null || ! $request->user()?->hasRole('admin') || AdminScopeContext::isGlobalAdmin($request->user())) {
            return;
        }

        $expected = AdminScopeContext::expectedTeacherTypeForScope($request->user()->admin_scope);

        if ($expected !== null && $teacherType !== $expected) {
            abort(403, 'Vous ne pouvez gérer que les enseignants de votre cycle.');
        }
    }

    private function ensureSingleMainAssignment(TeacherAssignment $assignment): void
    {
        if (! $assignment->is_main) {
            return;
        }

        TeacherAssignment::query()
            ->where('id', '<>', $assignment->id)
            ->where('classroom_id', $assignment->classroom_id)
            ->where('school_year_id', $assignment->school_year_id)
            ->where('is_main', true)
            ->update(['is_main' => false]);
    }

    /** @param  array<string, mixed>  $validated */
    private function teacherAttributes(array $validated): array
    {
        return collect($validated)->only(self::TEACHER_FIELDS)->all();
    }

    /** @param  array<string, mixed>  $validated */
    private function resolveSchoolYearId(Request $request, array $validated): int
    {
        $schoolYearId = $validated['school_year_id'] ?? SchoolYearContext::requestedOrCurrentId($request);

        if ($schoolYearId === null) {
            abort(422, 'Aucune année scolaire active. Définissez une année courante avant d\'affecter un enseignant.');
        }

        SchoolYearContext::assertNotArchivedById((int) $schoolYearId);

        return (int) $schoolYearId;
    }

    /** @param  array<string, mixed>  $validated */
    private function shouldSyncAssignments(array $validated): bool
    {
        return array_key_exists('classroom_id', $validated)
            || array_key_exists('classroom_ids', $validated)
            || array_key_exists('subject_id', $validated)
            || array_key_exists('subject_ids', $validated)
            || array_key_exists('secondary_role', $validated);
    }
}
