<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SchoolYearRequest;
use App\Http\Resources\Api\V1\SchoolYearResource;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\Student;
use App\Models\TeacherAssignment;
use App\Models\TimetableSlot;
use App\Services\SchoolClassGenerationService;
use App\Services\TermGenerationService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SchoolYearController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SchoolYearResource::collection(
            SchoolYear::query()
                ->with('terms.periods')
                ->orderByDesc('starts_on')
                ->paginate(20),
        );
    }

    public function current(): JsonResponse
    {
        $year = SchoolYearContext::current();

        return response()->json([
            'data' => $year
                ? SchoolYearResource::make($year->load('terms.periods'))->resolve()
                : null,
        ]);
    }

    public function store(SchoolYearRequest $request, SchoolClassGenerationService $schoolClassGeneration, TermGenerationService $termGeneration): JsonResponse
    {
        $year = DB::transaction(function () use ($request, $schoolClassGeneration, $termGeneration) {
            $year = SchoolYear::query()->create($request->validated());
            $this->ensureSingleCurrent($year);

            // Générer automatiquement 3 Trimestres (primaire) + 2 Semestres (secondaire) + leurs périodes
            $termGeneration->generateForYear($year);

            $classes = $schoolClassGeneration->generateBaseClasses($year);

            // Créer automatiquement une division A (capacité 40) par classe générée
            foreach ($classes as $schoolClass) {
                $schoolClassGeneration->addDivisions($schoolClass, 1, 40);
            }

            return $year->fresh();
        });

        return SchoolYearResource::make($year->loadCount('schoolClasses'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SchoolYear $schoolYear): SchoolYearResource
    {
        $schoolYear->load(['terms.periods', 'archivedBy']);
        $schoolYear->setAttribute('stats', $this->statsFor($schoolYear));

        return SchoolYearResource::make($schoolYear);
    }

    public function classroomDetails(SchoolYear $schoolYear, ClassRoom $classroom): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroom);

        $classroom->load('level');

        $terms = $schoolYear->terms()->with('periods')->orderBy('position')->orderBy('starts_on')->get();
        $termIds = $terms->pluck('id');
        $periods = Period::query()
            ->whereIn('term_id', $termIds)
            ->orderBy('position')
            ->get();

        $evaluations = Evaluation::query()
            ->with(['subject', 'term'])
            ->withCount('grades')
            ->whereIn('term_id', $termIds)
            ->where('classroom_id', $classroom->id)
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->get();

        $grades = Grade::query()
            ->whereIn('evaluation_id', $evaluations->pluck('id'))
            ->where('absent', false)
            ->whereNotNull('value')
            ->get();

        $attendances = Attendance::query()
            ->where('classroom_id', $classroom->id)
            ->whereDate('date', '>=', $schoolYear->starts_on)
            ->whereDate('date', '<=', $schoolYear->ends_on)
            ->get();

        $studentIds = Enrollment::query()
            ->forYear($schoolYear->id)
            ->forClassroom($classroom->id)
            ->pluck('student_id');
        $students = Student::query()
            ->with(['parents.user'])
            ->whereIn('id', $studentIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $assignments = TeacherAssignment::query()
            ->with(['teacher.user', 'subject'])
            ->where('school_year_id', $schoolYear->id)
            ->where('classroom_id', $classroom->id)
            ->orderBy('subject_id')
            ->orderBy('teacher_id')
            ->get();
        $courseAssignments = $assignments
            ->filter(fn (TeacherAssignment $assignment) => $assignment->subject_id !== null)
            ->values();

        $timetable = TimetableSlot::query()
            ->with(['subject', 'teacher.user'])
            ->where('school_year_id', $schoolYear->id)
            ->where('classroom_id', $classroom->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        $mainAssignment = $assignments->firstWhere('is_main', true);

        return response()->json([
            'data' => [
                'school_year' => [
                    'id' => $schoolYear->id,
                    'name' => $schoolYear->name,
                    'starts_on' => $schoolYear->starts_on->toDateString(),
                    'ends_on' => $schoolYear->ends_on->toDateString(),
                ],
                'classroom' => [
                    'id' => $classroom->id,
                    'full_name' => $classroom->full_name,
                    'section' => $classroom->section,
                    'level' => $classroom->level ? [
                        'id' => $classroom->level->id,
                        'name' => $classroom->level->name,
                    ] : null,
                ],
                'main_teacher' => $mainAssignment?->teacher ? [
                    'assignment_id' => $mainAssignment->id,
                    'id' => $mainAssignment->teacher->id,
                    'name' => $mainAssignment->teacher->user?->name,
                    'email' => $mainAssignment->teacher->user?->email,
                    'speciality' => $mainAssignment->teacher->speciality,
                    'subject_id' => $mainAssignment->subject_id,
                    'subject' => $mainAssignment->subject?->name,
                ] : null,
                'summary' => [
                    'students' => $students->count(),
                    'parents' => $students
                        ->flatMap(fn (Student $student) => $student->parents->pluck('id'))
                        ->unique()
                        ->count(),
                    'teachers' => $assignments->pluck('teacher_id')->unique()->count(),
                    'subjects' => $courseAssignments->pluck('subject_id')->unique()->count(),
                    'evaluations' => $evaluations->count(),
                    'grades_entered' => $grades->count(),
                    'grade_average' => $this->normalizedGradeAverage($evaluations->pluck('id')),
                    'attendance_records' => $attendances->count(),
                    'present' => $attendances->where('status', Attendance::STATUS_PRESENT)->count(),
                    'absences' => $attendances->where('status', Attendance::STATUS_ABSENT)->count(),
                    'justified_absences' => $attendances
                        ->where('status', Attendance::STATUS_ABSENT)
                        ->where('justified', true)
                        ->count(),
                    'unjustified_absences' => $attendances
                        ->where('status', Attendance::STATUS_ABSENT)
                        ->where('justified', false)
                        ->count(),
                    'lates' => $attendances->where('status', Attendance::STATUS_LATE)->count(),
                ],
                'students' => $students->map(function (Student $student) use ($attendances) {
                    $studentAttendances = $attendances->where('student_id', $student->id);

                    return [
                        'id' => $student->id,
                        'full_name' => $student->full_name,
                        'registration_number' => $student->registration_number,
                        'gender' => $student->gender,
                        'parents' => $student->parents->map(fn ($parent) => [
                            'id' => $parent->id,
                            'name' => $parent->user?->name,
                            'email' => $parent->user?->email,
                            'phone' => $parent->phone,
                            'relation' => $parent->pivot?->relation,
                        ])->values()->all(),
                        'attendance' => [
                            'present' => $studentAttendances->where('status', Attendance::STATUS_PRESENT)->count(),
                            'absences' => $studentAttendances->where('status', Attendance::STATUS_ABSENT)->count(),
                            'unjustified_absences' => $studentAttendances
                                ->where('status', Attendance::STATUS_ABSENT)
                                ->where('justified', false)
                                ->count(),
                            'lates' => $studentAttendances->where('status', Attendance::STATUS_LATE)->count(),
                        ],
                    ];
                })->values()->all(),
                'parents' => $this->parentsForStudents($students),
                'courses' => $courseAssignments->map(fn (TeacherAssignment $assignment) => [
                    'id' => $assignment->id,
                    'subject' => $assignment->subject ? [
                        'id' => $assignment->subject->id,
                        'name' => $assignment->subject->name,
                    ] : null,
                    'teacher' => $assignment->teacher ? [
                        'id' => $assignment->teacher->id,
                        'name' => $assignment->teacher->user?->name,
                        'email' => $assignment->teacher->user?->email,
                        'speciality' => $assignment->teacher->speciality,
                    ] : null,
                ])->values()->all(),
                'timetable' => $timetable->map(fn (TimetableSlot $slot) => [
                    'id' => $slot->id,
                    'day_of_week' => $slot->day_of_week,
                    'starts_at' => substr((string) $slot->starts_at, 0, 5),
                    'ends_at' => substr((string) $slot->ends_at, 0, 5),
                    'room' => $slot->room,
                    'subject' => $slot->subject ? [
                        'id' => $slot->subject->id,
                        'name' => $slot->subject->name,
                    ] : null,
                    'teacher' => $slot->teacher ? [
                        'id' => $slot->teacher->id,
                        'name' => $slot->teacher->user?->name,
                    ] : null,
                ])->values()->all(),
                'recent_attendances' => Attendance::query()
                    ->with(['student', 'subject'])
                    ->where('classroom_id', $classroom->id)
                    ->whereDate('date', '>=', $schoolYear->starts_on)
                    ->whereDate('date', '<=', $schoolYear->ends_on)
                    ->orderByDesc('date')
                    ->limit(12)
                    ->get()
                    ->map(fn (Attendance $attendance) => [
                        'id' => $attendance->id,
                        'date' => $attendance->date?->toDateString(),
                        'status' => $attendance->status,
                        'justified' => $attendance->justified,
                        'student' => $attendance->student ? [
                            'id' => $attendance->student->id,
                            'full_name' => $attendance->student->full_name,
                        ] : null,
                        'subject' => $attendance->subject ? [
                            'id' => $attendance->subject->id,
                            'name' => $attendance->subject->name,
                        ] : null,
                    ])->values()->all(),
                'evaluations' => $evaluations->map(fn (Evaluation $evaluation) => [
                    'id' => $evaluation->id,
                    'name' => $evaluation->name,
                    'type' => $evaluation->type,
                    'held_on' => $evaluation->held_on?->toDateString(),
                    'max_value' => (float) $evaluation->max_value,
                    'grades_count' => $evaluation->grades_count,
                    'subject' => $evaluation->subject ? [
                        'id' => $evaluation->subject->id,
                        'name' => $evaluation->subject->name,
                    ] : null,
                    'term' => $evaluation->term ? [
                        'id' => $evaluation->term->id,
                        'name' => $evaluation->term->name,
                    ] : null,
                ])->values()->all(),
            ],
        ]);
    }

    public function update(SchoolYearRequest $request, SchoolYear $schoolYear): JsonResponse|SchoolYearResource
    {
        if ($schoolYear->isArchived()) {
            return $this->archivedReadOnlyResponse();
        }

        DB::transaction(function () use ($request, $schoolYear) {
            $schoolYear->update($request->validated());
            $this->ensureSingleCurrent($schoolYear->refresh());
        });

        return SchoolYearResource::make($schoolYear->fresh()->load(['terms.periods', 'archivedBy']));
    }

    public function destroy(SchoolYear $schoolYear): JsonResponse
    {
        if ($schoolYear->isArchived()) {
            return $this->archivedReadOnlyResponse();
        }

        $schoolYear->delete();

        return response()->json(null, 204);
    }

    /**
     * Archive l'année scolaire : verrouille en lecture seule et conserve la date /
     * l'utilisateur responsable. L'année archivée ne peut plus être courante.
     */
    public function archive(Request $request, SchoolYear $schoolYear): SchoolYearResource
    {
        if ($schoolYear->isArchived()) {
            return SchoolYearResource::make($schoolYear->load(['terms.periods', 'archivedBy']));
        }

        DB::transaction(function () use ($request, $schoolYear) {
            $schoolYear->update([
                'is_current' => false,
                'closed_at' => $schoolYear->closed_at ?? now(),
                'archived_at' => now(),
                'archived_by_id' => $request->user()?->id,
            ]);
        });

        return SchoolYearResource::make($schoolYear->fresh()->load(['terms.periods', 'archivedBy']));
    }

    /**
     * Annule l'archivage : l'année redevient modifiable.
     */
    public function unarchive(SchoolYear $schoolYear): SchoolYearResource
    {
        if (! $schoolYear->isArchived()) {
            return SchoolYearResource::make($schoolYear->load(['terms.periods', 'archivedBy']));
        }

        $schoolYear->update([
            'archived_at' => null,
            'archived_by_id' => null,
        ]);

        return SchoolYearResource::make($schoolYear->fresh()->load(['terms.periods', 'archivedBy']));
    }

    private function archivedReadOnlyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Année scolaire archivée : les données ne peuvent plus être modifiées.',
        ], 423);
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

    /**
     * @return array<string, mixed>
     */
    private function statsFor(SchoolYear $schoolYear): array
    {
        $terms = $schoolYear->terms()->orderBy('position')->orderBy('starts_on')->get();
        $termIds = $terms->pluck('id');
        $periods = Period::query()
            ->whereIn('term_id', $termIds)
            ->orderBy('term_id')
            ->orderBy('position')
            ->get();

        $evaluationQuery = Evaluation::query()
            ->whereIn('term_id', $termIds)
            ->whereIn('classroom_id', $this->classroomIdsFor($schoolYear, $termIds));
        $evaluationIds = $evaluationQuery->pluck('id');

        $gradesQuery = Grade::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->where('absent', false)
            ->whereNotNull('value');

        $attendanceQuery = Attendance::query()
            ->whereIn('classroom_id', $this->classroomIdsFor($schoolYear, $termIds))
            ->whereDate('date', '>=', $schoolYear->starts_on)
            ->whereDate('date', '<=', $schoolYear->ends_on);

        $assignmentQuery = TeacherAssignment::query()
            ->where('school_year_id', $schoolYear->id)
            ->whereIn('classroom_id', $this->classroomIdsFor($schoolYear, $termIds));

        $classroomIds = $this->classroomIdsFor($schoolYear, $termIds);
        $studentIds = Enrollment::query()
            ->forYear($schoolYear->id)
            ->whereIn('classroom_id', $classroomIds)
            ->pluck('student_id');

        $gradeAverage = $this->normalizedGradeAverage($evaluationIds);

        $studentRows = $this->studentRows($schoolYear, $studentIds, $evaluationIds);
        $passingCount = collect($studentRows)
            ->filter(fn ($row) => $row['grade_average'] !== null && $row['grade_average'] >= 10)
            ->count();
        $evaluatedCount = collect($studentRows)
            ->filter(fn ($row) => $row['grade_average'] !== null)
            ->count();
        $successRate = $evaluatedCount > 0
            ? round(($passingCount / $evaluatedCount) * 100, 1)
            : null;

        return [
            'summary' => [
                'classes' => $classroomIds->count(),
                'students' => $studentIds->count(),
                'parents' => DB::table('parent_student')
                    ->whereIn('student_id', $studentIds)
                    ->distinct('parent_profile_id')
                    ->count('parent_profile_id'),
                'terms' => $terms->count(),
                'closed_terms' => $terms->whereNotNull('closed_at')->count(),
                'periods' => $periods->count(),
                'closed_periods' => $periods->whereNotNull('closed_at')->count(),
                'teacher_assignments' => (clone $assignmentQuery)->count(),
                'assigned_teachers' => (clone $assignmentQuery)->distinct('teacher_id')->count('teacher_id'),
                'assigned_classrooms' => (clone $assignmentQuery)->distinct('classroom_id')->count('classroom_id'),
                'assigned_subjects' => (clone $assignmentQuery)->distinct('subject_id')->count('subject_id'),
                'evaluations' => $evaluationIds->count(),
                'grades_entered' => (clone $gradesQuery)->count(),
                'grade_average' => $gradeAverage !== null ? round((float) $gradeAverage, 2) : null,
                'success_rate' => $successRate,
                'students_passing' => $passingCount,
                'students_evaluated' => $evaluatedCount,
                'attendance_records' => (clone $attendanceQuery)->count(),
                'absences' => (clone $attendanceQuery)->where('status', Attendance::STATUS_ABSENT)->count(),
                'unjustified_absences' => (clone $attendanceQuery)
                    ->where('status', Attendance::STATUS_ABSENT)
                    ->where('justified', false)
                    ->count(),
                'lates' => (clone $attendanceQuery)->where('status', Attendance::STATUS_LATE)->count(),
            ],
            'terms' => $terms->map(fn ($term) => $this->termStats($term))->values()->all(),
            'periods' => $periods->map(fn (Period $period) => [
                'id' => $period->id,
                'term_id' => $period->term_id,
                'school_year_id' => $period->school_year_id,
                'name' => $period->name,
                'position' => $period->position,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'closed_at' => $period->closed_at,
                'is_closed' => $period->isClosed(),
            ])->values()->all(),
            'class_averages' => $this->classAverages($schoolYear, $termIds, $classroomIds),
            'monthly_attendance' => $this->monthlyAttendance($schoolYear),
            'students' => $studentRows,
            'history' => $this->historyFor($schoolYear, $terms),
        ];
    }

    private function classroomIdsFor(SchoolYear $schoolYear, $termIds): Collection
    {
        $assignmentClassroomIds = TeacherAssignment::query()
            ->where('school_year_id', $schoolYear->id)
            ->distinct()
            ->pluck('classroom_id');

        $evaluationClassroomIds = Evaluation::query()
            ->whereIn('term_id', $termIds)
            ->distinct()
            ->pluck('classroom_id');

        $attendanceClassroomIds = Attendance::query()
            ->whereDate('date', '>=', $schoolYear->starts_on)
            ->whereDate('date', '<=', $schoolYear->ends_on)
            ->whereNotNull('classroom_id')
            ->distinct()
            ->pluck('classroom_id');

        $divisionClassroomIds = ClassRoom::query()
            ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $schoolYear->id))
            ->pluck('id');

        $enrolledClassroomIds = Enrollment::query()
            ->forYear($schoolYear->id)
            ->whereNotNull('classroom_id')
            ->distinct()
            ->pluck('classroom_id');

        $ids = $assignmentClassroomIds
            ->merge($evaluationClassroomIds)
            ->merge($attendanceClassroomIds)
            ->merge($divisionClassroomIds)
            ->merge($enrolledClassroomIds)
            ->filter()
            ->unique()
            ->values();

        if (request()->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin(request()->user())) {
            $allowed = AdminScopeContext::allowedClassroomIds(request()->user());

            return $ids
                ->filter(fn ($id) => $allowed->contains((int) $id))
                ->values();
        }

        return $ids;
    }

    private function classAverages(SchoolYear $schoolYear, $termIds, $classroomIds): array
    {
        if ($classroomIds->isEmpty()) {
            return [];
        }

        return ClassRoom::query()
            ->whereIn('id', $classroomIds)
            ->with(['level', 'schoolOption', 'schoolClass.level', 'schoolClass.schoolOption'])
            ->get()
            ->sortBy('full_name')
            ->map(function (ClassRoom $classroom) use ($schoolYear, $termIds) {
                $studentIds = Enrollment::query()
                    ->forYear($schoolYear->id)
                    ->forClassroom($classroom->id)
                    ->pluck('student_id');

                $assignmentQuery = TeacherAssignment::query()
                    ->where('school_year_id', $schoolYear->id)
                    ->where('classroom_id', $classroom->id);

                $evaluationIds = Evaluation::query()
                    ->whereIn('term_id', $termIds)
                    ->where('classroom_id', $classroom->id)
                    ->pluck('id');

                $gradesQuery = Grade::query()
                    ->whereIn('evaluation_id', $evaluationIds)
                    ->where('absent', false)
                    ->whereNotNull('value');

                $attendanceQuery = Attendance::query()
                    ->where('classroom_id', $classroom->id)
                    ->whereDate('date', '>=', $schoolYear->starts_on)
                    ->whereDate('date', '<=', $schoolYear->ends_on);

                $gradeAverage = $this->normalizedGradeAverage($evaluationIds);

                return [
                    'classroom_id' => $classroom->id,
                    'classroom' => $classroom->full_name,
                    'class_code' => $classroom->schoolClass?->name ?? $classroom->section,
                    'school_class_id' => $classroom->school_class_id,
                    'cycle' => $classroom->level?->cycle,
                    'level_name' => $classroom->level?->name,
                    'option_name' => $classroom->schoolOption?->name ?? $classroom->schoolClass?->schoolOption?->name,
                    'capacity' => $classroom->capacity ?? 40,
                    'student_count' => $studentIds->count(),
                    'parent_count' => DB::table('parent_student')
                        ->whereIn('student_id', $studentIds)
                        ->distinct('parent_profile_id')
                        ->count('parent_profile_id'),
                    'teacher_count' => (clone $assignmentQuery)->distinct('teacher_id')->count('teacher_id'),
                    'subject_count' => (clone $assignmentQuery)->distinct('subject_id')->count('subject_id'),
                    'evaluations' => $evaluationIds->count(),
                    'grades_entered' => (clone $gradesQuery)->count(),
                    'grade_average' => $gradeAverage !== null ? round((float) $gradeAverage, 2) : null,
                    'attendance_records' => (clone $attendanceQuery)->count(),
                    'absences' => (clone $attendanceQuery)->where('status', Attendance::STATUS_ABSENT)->count(),
                    'lates' => (clone $attendanceQuery)->where('status', Attendance::STATUS_LATE)->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function parentsForStudents($students): array
    {
        return $students
            ->flatMap(fn (Student $student) => $student->parents->map(fn ($parent) => [
                'id' => $parent->id,
                'user_id' => $parent->user_id,
                'name' => $parent->user?->name,
                'email' => $parent->user?->email,
                'phone' => $parent->phone,
                'address' => $parent->address,
                'child' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'relation' => $parent->pivot?->relation,
                ],
            ]))
            ->groupBy('id')
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'id' => $first['id'],
                    'user_id' => $first['user_id'],
                    'name' => $first['name'],
                    'email' => $first['email'],
                    'phone' => $first['phone'],
                    'address' => $first['address'],
                    'children' => $rows->pluck('child')->values()->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function termStats($term): array
    {
        $evaluationIds = Evaluation::query()
            ->where('term_id', $term->id)
            ->pluck('id');

        $gradesQuery = Grade::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->where('absent', false)
            ->whereNotNull('value');

        $attendanceQuery = Attendance::query()
            ->whereDate('date', '>=', $term->starts_on)
            ->whereDate('date', '<=', $term->ends_on);

        $gradeAverage = $this->normalizedGradeAverage($evaluationIds);

        return [
            'id' => $term->id,
            'name' => $term->name,
            'position' => $term->position,
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
            'is_closed' => $term->isClosed(),
            'evaluations' => $evaluationIds->count(),
            'grades_entered' => (clone $gradesQuery)->count(),
            'grade_average' => $gradeAverage !== null ? round((float) $gradeAverage, 2) : null,
            'absences' => (clone $attendanceQuery)->where('status', Attendance::STATUS_ABSENT)->count(),
            'lates' => (clone $attendanceQuery)->where('status', Attendance::STATUS_LATE)->count(),
        ];
    }

    /**
     * @return array<int, array{value:string,label:string,absences:int,lates:int}>
     */
    private function monthlyAttendance(SchoolYear $schoolYear): array
    {
        $start = Carbon::parse($schoolYear->starts_on)->startOfMonth();
        $end = Carbon::parse($schoolYear->ends_on)->startOfMonth();

        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            $monthStart = $cursor->copy()->startOfMonth()->max(Carbon::parse($schoolYear->starts_on));
            $monthEnd = $cursor->copy()->endOfMonth()->min(Carbon::parse($schoolYear->ends_on));

            $query = Attendance::query()
                ->whereDate('date', '>=', $monthStart)
                ->whereDate('date', '<=', $monthEnd);

            $months[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $this->monthLabel($cursor),
                'absences' => (clone $query)->where('status', Attendance::STATUS_ABSENT)->count(),
                'lates' => (clone $query)->where('status', Attendance::STATUS_LATE)->count(),
            ];
        }

        return $months;
    }

    private function monthLabel(Carbon $month): string
    {
        $labels = [
            1 => 'Jan',
            2 => 'Fév',
            3 => 'Mar',
            4 => 'Avr',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juil',
            8 => 'Août',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Déc',
        ];

        return $labels[(int) $month->format('n')].' '.$month->format('Y');
    }

    /**
     * Liste enrichie des élèves de l'année (classe, moyenne, absences, statut final).
     *
     * @return array<int, array<string, mixed>>
     */
    private function studentRows(SchoolYear $schoolYear, Collection $studentIds, Collection $evaluationIds): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }

        $students = Student::query()
            ->whereIn('id', $studentIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // Classe occupée par l'élève CETTE année-là (pas le cache courant).
        $classroomByStudent = Enrollment::query()
            ->forYear($schoolYear->id)
            ->whereIn('student_id', $studentIds)
            ->with('classroom.level')
            ->get()
            ->keyBy('student_id');

        $gradesByStudent = Grade::query()
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereIn('grades.evaluation_id', $evaluationIds)
            ->where('grades.absent', false)
            ->whereNotNull('grades.value')
            ->where('evaluations.max_value', '>', 0)
            ->whereIn('grades.student_id', $studentIds)
            ->get([
                'grades.student_id',
                DB::raw('(grades.value * 20.0) / evaluations.max_value as normalized_value'),
            ])
            ->groupBy('student_id');

        $attendanceByStudent = Attendance::query()
            ->whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $schoolYear->starts_on)
            ->whereDate('date', '<=', $schoolYear->ends_on)
            ->get(['student_id', 'status', 'justified'])
            ->groupBy('student_id');

        return $students->map(function (Student $student) use ($gradesByStudent, $attendanceByStudent, $classroomByStudent) {
            $grades = $gradesByStudent->get($student->id, collect());
            $average = $grades->isEmpty() ? null : round((float) $grades->avg('normalized_value'), 2);

            $attendance = $attendanceByStudent->get($student->id, collect());
            $absences = $attendance->where('status', Attendance::STATUS_ABSENT)->count();
            $unjustified = $attendance
                ->where('status', Attendance::STATUS_ABSENT)
                ->where('justified', false)
                ->count();
            $lates = $attendance->where('status', Attendance::STATUS_LATE)->count();

            $classroom = $classroomByStudent->get($student->id)?->classroom;

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'registration_number' => $student->registration_number,
                'gender' => $student->gender,
                'classroom_id' => $classroom?->id,
                'classroom' => $classroom?->full_name,
                'level' => $classroom?->level?->name,
                'grade_average' => $average,
                'final_status' => $this->finalStatus($average),
                'absences' => $absences,
                'unjustified_absences' => $unjustified,
                'lates' => $lates,
            ];
        })->values()->all();
    }

    private function normalizedGradeAverage(Collection $evaluationIds, ?Collection $studentIds = null): ?float
    {
        if ($evaluationIds->isEmpty() || ($studentIds !== null && $studentIds->isEmpty())) {
            return null;
        }

        $average = Grade::query()
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereIn('grades.evaluation_id', $evaluationIds)
            ->when($studentIds !== null, fn ($query) => $query->whereIn('grades.student_id', $studentIds))
            ->where('grades.absent', false)
            ->whereNotNull('grades.value')
            ->where('evaluations.max_value', '>', 0)
            ->selectRaw('AVG((grades.value * 20.0) / evaluations.max_value) as average')
            ->value('average');

        return $average !== null ? round((float) $average, 2) : null;
    }

    private function finalStatus(?float $average): string
    {
        if ($average === null) {
            return 'en_cours';
        }

        return $average >= 10 ? 'admis' : 'redouble';
    }

    /**
     * Historique de clôture / archivage pour le bloc "Historique".
     *
     * @param  Collection<int, Term>  $terms
     * @return array<string, mixed>
     */
    private function historyFor(SchoolYear $schoolYear, Collection $terms): array
    {
        $schoolYear->loadMissing('archivedBy');

        return [
            'closed_at' => $schoolYear->closed_at,
            'archived_at' => $schoolYear->archived_at,
            'archived_by' => $schoolYear->archivedBy ? [
                'id' => $schoolYear->archivedBy->id,
                'name' => $schoolYear->archivedBy->name,
                'email' => $schoolYear->archivedBy->email,
            ] : null,
            'terms' => $terms->map(fn ($term) => [
                'id' => $term->id,
                'name' => $term->name,
                'position' => $term->position,
                'closed_at' => $term->closed_at,
                'is_closed' => $term->isClosed(),
            ])->values()->all(),
        ];
    }
}
