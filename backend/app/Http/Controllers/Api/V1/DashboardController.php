<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\AttendanceStatsService;
use App\Services\LowGradeAlertService;
use App\Services\ReportCardService;
use App\Support\AdminScopeContext;
use App\Support\DevCalendarContext;
use App\Support\SchoolYearContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportCardService $reportCards,
        private readonly LowGradeAlertService $lowGradeAlerts,
    ) {}

    /** Dashboard admin — effectifs, taux d'absences, moyennes (CDC §4.7). */
    public function admin(Request $request, AttendanceStatsService $attendanceStats): JsonResponse
    {
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $schoolYearId = $schoolYear?->id;
        $currentTerm = $this->currentTerm($schoolYear, $request);
        $availableMonths = $this->availableMonths($schoolYear);
        $availableTerms = $this->availableTerms($schoolYear);
        $period = $this->periodFromRequest($request, $currentTerm, $schoolYear);

        $studentsCountQuery = Student::query();
        SchoolYearContext::applyStudentEnrollmentYearId($studentsCountQuery, $schoolYearId);
        AdminScopeContext::applyStudentScope($studentsCountQuery, $request);
        $totalStudents = $studentsCountQuery->count();
        $totalTeachers = $schoolYearId !== null
            ? Teacher::query()
                ->whereHas('assignments', fn ($query) => $query
                    ->where('school_year_id', $schoolYearId)
                    ->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                        ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user()))))
                ->count()
            : Teacher::query()
                ->when($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user()), fn ($query) => $query
                    ->whereHas('assignments.classroom.level', fn ($levelQuery) => $levelQuery
                        ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user()))))
                ->count();
        $totalParents = $schoolYearId !== null
            ? ParentProfile::query()
                ->whereHas('students', function ($query) use ($request, $schoolYearId): void {
                    SchoolYearContext::applyStudentEnrollmentYearId($query, $schoolYearId);
                    if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
                        $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                            ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
                    }
                })
                ->count()
            : ParentProfile::query()
                ->when($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user()), fn ($query) => $query
                    ->whereHas('students.classroom.level', fn ($levelQuery) => $levelQuery
                        ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user()))))
                ->count();
        $totalUsers = User::query()->count();
        $totalClassrooms = $this->classroomsQueryForSchoolYear($schoolYearId)->count();
        $levelsQuery = Level::query();
        AdminScopeContext::applyLevelScope($levelsQuery, $request);
        $totalLevels = $levelsQuery->count();
        $totalSubjects = $schoolYearId !== null
            ? Subject::query()
                ->whereHas('assignments', fn ($query) => $query->where('school_year_id', $schoolYearId))
                ->count()
            : Subject::query()->count();

        $totalAbsences = $this->applyPeriod(
            $this->attendanceQueryForAdminScope($request)->where('status', Attendance::STATUS_ABSENT),
            'date',
            $period,
        )->count();
        $unjustifiedAbsences = $this->applyPeriod(
            $this->attendanceQueryForAdminScope($request)
                ->where('status', Attendance::STATUS_ABSENT)
                ->where('justified', false),
            'date',
            $period,
        )->count();
        $totalLates = $this->applyPeriod(
            $this->attendanceQueryForAdminScope($request)->where('status', Attendance::STATUS_LATE),
            'date',
            $period,
        )->count();

        $absenceRate = $totalStudents > 0
            ? round(($totalAbsences / max($totalStudents, 1)) * 100, 1)
            : 0;

        $classrooms = $this->classroomsQueryForSchoolYear($schoolYearId)->with('level')->get();
        $classroomIds = $classrooms->pluck('id');

        // Effectifs par classe (année en cours) — 1 requête agrégée.
        $countQuery = Student::query()->whereIn('classroom_id', $classroomIds);
        SchoolYearContext::applyStudentEnrollmentYearId($countQuery, $schoolYearId);
        $studentCounts = $countQuery
            ->selectRaw('classroom_id, count(*) as aggregate')
            ->groupBy('classroom_id')
            ->pluck('aggregate', 'classroom_id');

        // Moyenne par classe (notes normalisées sur 20, filtrées période + année) — 1 requête.
        $averageQuery = Grade::query()
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereIn('evaluations.classroom_id', $classroomIds)
            ->where('grades.absent', false)
            ->whereNotNull('grades.value')
            ->where('evaluations.max_value', '>', 0);
        if ($schoolYearId !== null) {
            $averageQuery
                ->join('terms', 'terms.id', '=', 'evaluations.term_id')
                ->where('terms.school_year_id', $schoolYearId);
        }
        $this->applyPeriod($averageQuery, 'evaluations.held_on', $period);
        $classAverages = $averageQuery
            ->selectRaw('evaluations.classroom_id as classroom_id, AVG((grades.value * 20.0) / evaluations.max_value) as aggregate')
            ->groupBy('evaluations.classroom_id')
            ->pluck('aggregate', 'classroom_id');

        // Absences par classe (période) — 1 requête agrégée.
        $absenceQuery = Attendance::query()
            ->whereIn('classroom_id', $classroomIds)
            ->where('status', Attendance::STATUS_ABSENT);
        $this->applyPeriod($absenceQuery, 'date', $period);
        $absencesByClass = $absenceQuery
            ->selectRaw('classroom_id, count(*) as aggregate')
            ->groupBy('classroom_id')
            ->pluck('aggregate', 'classroom_id');

        $classroomStats = [];
        foreach ($classrooms as $classroom) {
            $studentCount = (int) ($studentCounts[$classroom->id] ?? 0);
            if ($studentCount === 0) {
                continue;
            }
            $average = $classAverages[$classroom->id] ?? null;
            $classroomStats[] = [
                'classroom_id' => $classroom->id,
                'full_name' => $classroom->full_name,
                'student_count' => $studentCount,
                'class_average' => $average !== null ? round((float) $average, 2) : null,
                'absences' => (int) ($absencesByClass[$classroom->id] ?? 0),
            ];
        }

        $gradedClasses = collect($classroomStats)->filter(fn (array $row) => $row['class_average'] !== null);
        $institutionAverage = $gradedClasses->isNotEmpty()
            ? round((float) $gradedClasses->avg('class_average'), 1)
            : null;

        $lowGradeThreshold = $this->lowGradeAlerts->threshold();
        $studentsAtRiskCount = $this->countStudentsAtRisk($request, $currentTerm, $lowGradeThreshold);
        $classesWithUnjustified = collect($classroomStats)
            ->filter(fn (array $row) => $row['absences'] > 0)
            ->count();

        $attendanceBreakdown = $this->attendanceBreakdown($request, $period);
        $monthlyAverages = $schoolYear !== null
            ? $this->monthlyAverages($schoolYear, $request)
            : [];
        $topStudents = $this->topStudents($request, $currentTerm);
        $watchlist = $this->buildWatchlist($classroomStats, $request, $currentTerm, $period, $lowGradeThreshold);
        $averageDelta = $this->institutionAverageDelta($schoolYear, $request, $period);

        return response()->json([
            'data' => [
                'counts' => [
                    'students' => $totalStudents,
                    'teachers' => $totalTeachers,
                    'parents' => $totalParents,
                    'users' => $totalUsers,
                    'classrooms' => $totalClassrooms,
                    'levels' => $totalLevels,
                    'subjects' => $totalSubjects,
                ],
                'attendance' => [
                    'total_absences' => $totalAbsences,
                    'unjustified' => $unjustifiedAbsences,
                    'total_lates' => $totalLates,
                    'absence_rate_per_student' => $absenceRate,
                ],
                'current_term' => $currentTerm ? [
                    'id' => $currentTerm->id,
                    'name' => $currentTerm->name,
                ] : null,
                'period' => $this->periodPayload($period),
                'available_months' => $availableMonths,
                'available_terms' => $availableTerms,
                'monthly_attendance' => $schoolYear !== null
                    ? $attendanceStats->monthlyAttendance($schoolYear)
                    : [],
                'monthly_averages' => $monthlyAverages,
                'classrooms' => $classroomStats,
                'insights' => [
                    'institution_average' => $institutionAverage,
                    'institution_average_delta' => $averageDelta,
                    'students_at_risk_count' => $studentsAtRiskCount,
                    'classes_with_unjustified_absences' => $classesWithUnjustified,
                    'low_grade_threshold' => $lowGradeThreshold,
                    'attendance_breakdown' => $attendanceBreakdown,
                    'top_students' => $topStudents,
                    'watchlist' => $watchlist,
                ],
            ],
        ]);
    }

    /** Dashboard enseignant — résultats par classe et cours (CDC §4.7). */
    public function teacher(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $schoolYearId = $schoolYear?->id;
        $currentTerm = $this->currentTerm($schoolYear, $request);
        $availableMonths = $this->availableMonths($schoolYear);
        $availableTerms = $this->availableTerms($schoolYear);
        $period = $this->periodFromRequest($request, $currentTerm, $schoolYear);

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher === null) {
            return response()->json([
                'data' => [
                    'assignments' => [],
                    'current_term' => null,
                    'available_months' => $availableMonths,
                    'available_terms' => $availableTerms,
                    'period' => $this->periodPayload($period),
                ],
            ]);
        }

        $assignments = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->when($schoolYearId !== null, fn ($query) => $query->where('school_year_id', $schoolYearId))
            ->with(['classroom.level', 'subject'])
            ->get();

        $stats = [];
        foreach ($assignments as $assignment) {
            $classroom = $assignment->classroom;
            $subject = $assignment->subject;
            if ($classroom === null || $subject === null) {
                continue;
            }

            $studentQuery = Student::query()->where('classroom_id', $classroom->id);
            SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $schoolYearId);
            $studentCount = $studentQuery->count();

            $classAvg = null;
            $evalCount = 0;
            $gradeCount = 0;

            $evaluationQuery = Evaluation::query()
                ->where('classroom_id', $classroom->id)
                ->where('subject_id', $subject->id);
            if ($schoolYearId !== null) {
                $evaluationQuery->whereHas('term', fn ($query) => $query->where('school_year_id', $schoolYearId));
            }
            $evals = $this->applyPeriod(
                $evaluationQuery,
                'held_on',
                $period,
            )->pluck('id');

            $evalCount = $evals->count();

            $grades = $this->normalizedGradeValues($evals);

            $gradeCount = $grades->count();
            $classAvg = $grades->isNotEmpty() ? round($grades->avg(), 2) : null;

            $absCount = $this->applyPeriod(
                Attendance::query()
                    ->where('classroom_id', $classroom->id)
                    ->where('subject_id', $subject->id)
                    ->where('status', Attendance::STATUS_ABSENT),
                'date',
                $period,
            )->count();

            $stats[] = [
                'classroom_id' => $classroom->id,
                'classroom' => $classroom->full_name,
                'subject_id' => $subject->id,
                'subject' => $subject->name,
                'student_count' => $studentCount,
                'evaluations' => $evalCount,
                'grades_entered' => $gradeCount,
                'class_average' => $classAvg,
                'absences' => $absCount,
            ];
        }

        return response()->json([
            'data' => [
                'teacher_name' => $user->name,
                'current_term' => $currentTerm ? [
                    'id' => $currentTerm->id,
                    'name' => $currentTerm->name,
                ] : null,
                'period' => $this->periodPayload($period),
                'available_months' => $availableMonths,
                'available_terms' => $availableTerms,
                'assignments' => $stats,
            ],
        ]);
    }

    private function normalizedGradeValues($evaluationIds)
    {
        if ($evaluationIds->isEmpty()) {
            return collect();
        }

        return Grade::query()
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereIn('grades.evaluation_id', $evaluationIds)
            ->where('grades.absent', false)
            ->whereNotNull('grades.value')
            ->where('evaluations.max_value', '>', 0)
            ->selectRaw('(grades.value * 20.0) / evaluations.max_value as normalized_value')
            ->pluck('normalized_value')
            ->map(fn ($value) => (float) $value);
    }

    private function currentTerm(?SchoolYear $schoolYear, Request $request): ?Term
    {
        return DevCalendarContext::resolveCurrentTermForDashboard(
            $schoolYear,
            AdminScopeContext::allowedTermApplicableCycles($request->user()),
        );
    }

    /** @return array{key:string,label:string,starts_on:?string,ends_on:?string,empty:bool} */
    private function periodFromRequest(Request $request, ?Term $currentTerm, ?SchoolYear $schoolYear): array
    {
        $key = (string) $request->query('period', 'year');
        if (! in_array($key, ['week', 'month', 'term', 'year', 'all'], true)) {
            $key = 'year';
        }

        $now = now();

        if ($key === 'week') {
            return [
                'key' => $key,
                'label' => 'Cette semaine',
                'starts_on' => $now->copy()->startOfWeek()->toDateString(),
                'ends_on' => $now->copy()->endOfWeek()->toDateString(),
                'empty' => false,
            ];
        }

        if ($key === 'month') {
            $monthStart = $this->monthFromRequest($request);

            return [
                'key' => $key,
                'label' => $this->monthLabel($monthStart),
                'starts_on' => $monthStart->copy()->startOfMonth()->toDateString(),
                'ends_on' => $monthStart->copy()->endOfMonth()->toDateString(),
                'empty' => false,
            ];
        }

        if ($key === 'term') {
            $term = $this->termFromRequest($request, $currentTerm, $schoolYear);

            if ($term === null) {
                return [
                    'key' => $key,
                    'label' => 'Aucun trimestre actif',
                    'starts_on' => null,
                    'ends_on' => null,
                    'empty' => true,
                ];
            }

            return [
                'key' => $key,
                'label' => $term->name,
                'starts_on' => $term->starts_on->toDateString(),
                'ends_on' => $term->ends_on->toDateString(),
                'empty' => false,
            ];
        }

        if ($key === 'year') {
            if ($schoolYear !== null) {
                return [
                    'key' => $key,
                    'label' => $schoolYear->name,
                    'starts_on' => $schoolYear->starts_on->toDateString(),
                    'ends_on' => $schoolYear->ends_on->toDateString(),
                    'empty' => false,
                ];
            }

            return [
                'key' => $key,
                'label' => 'Cette année',
                'starts_on' => $now->copy()->startOfYear()->toDateString(),
                'ends_on' => $now->copy()->endOfYear()->toDateString(),
                'empty' => false,
            ];
        }

        return [
            'key' => 'all',
            'label' => 'Toutes les données',
            'starts_on' => null,
            'ends_on' => null,
            'empty' => false,
        ];
    }

    private function applyPeriod($query, string $column, array $period)
    {
        if ($period['empty']) {
            return $query->whereRaw('1 = 0');
        }

        if ($period['starts_on'] !== null) {
            $query->whereDate($column, '>=', $period['starts_on']);
        }

        if ($period['ends_on'] !== null) {
            $query->whereDate($column, '<=', $period['ends_on']);
        }

        return $query;
    }

    private function periodPayload(array $period): array
    {
        return [
            'key' => $period['key'],
            'label' => $period['label'],
            'starts_on' => $period['starts_on'],
            'ends_on' => $period['ends_on'],
        ];
    }

    private function monthFromRequest(Request $request): Carbon
    {
        $month = (string) $request->query('month', '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
            } catch (\Throwable) {
                // fallback below
            }
        }

        return now()->startOfMonth();
    }

    private function termFromRequest(Request $request, ?Term $currentTerm, ?SchoolYear $schoolYear): ?Term
    {
        $termId = (string) $request->query('term_id', '');

        if (ctype_digit($termId)) {
            $query = Term::query()->whereKey((int) $termId);
            if ($schoolYear !== null) {
                $query->where('school_year_id', $schoolYear->id);
            }

            $term = $query->first();
            if ($term !== null) {
                return $term;
            }
        }

        if (
            $currentTerm !== null
            && ($schoolYear === null || $currentTerm->school_year_id === $schoolYear->id)
        ) {
            return $currentTerm;
        }

        if ($schoolYear === null) {
            return null;
        }

        return Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->orderBy('position')
            ->orderBy('starts_on')
            ->first();
    }

    /** @return array<int, array{value:string,label:string}> */
    private function availableMonths(?SchoolYear $schoolYear = null): array
    {
        if ($schoolYear === null) {
            $start = now()->copy()->startOfYear();
            $end = now()->copy()->endOfYear();
        } else {
            $start = Carbon::parse($schoolYear->starts_on)->startOfMonth();
            $end = Carbon::parse($schoolYear->ends_on)->startOfMonth();
        }

        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            $months[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $this->monthLabel($cursor),
            ];
        }

        return $months;
    }

    /** @return array<int, array{value:string,label:string}> */
    private function availableTerms(?SchoolYear $schoolYear = null): array
    {
        if ($schoolYear === null) {
            return [];
        }

        return Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->orderBy('position')
            ->orderBy('starts_on')
            ->get()
            ->map(fn (Term $term) => [
                'value' => (string) $term->id,
                'label' => $term->name,
            ])
            ->all();
    }

    private function classroomsQueryForSchoolYear(?int $schoolYearId)
    {
        $query = ClassRoom::query();
        AdminScopeContext::applyClassroomScope($query, request());

        if ($schoolYearId === null) {
            return $query;
        }

        return $query->where(function ($classroomQuery) use ($schoolYearId): void {
            $classroomQuery
                ->whereHas('enrollments', fn ($enrollmentQuery) => $enrollmentQuery
                    ->where('school_year_id', $schoolYearId))
                ->orWhereHas('teacherAssignments', fn ($assignmentQuery) => $assignmentQuery
                    ->where('school_year_id', $schoolYearId));
        });
    }

    private function attendanceQueryForAdminScope(Request $request)
    {
        $query = Attendance::query();
        if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
            $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }

        return $query;
    }

    private function monthLabel(Carbon $month): string
    {
        $labels = [
            1 => 'Janvier',
            2 => 'Février',
            3 => 'Mars',
            4 => 'Avril',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juillet',
            8 => 'Août',
            9 => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Décembre',
        ];

        return $labels[(int) $month->format('n')].' '.$month->format('Y');
    }

    private function monthShortLabel(Carbon $month): string
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

        return $labels[(int) $month->format('n')];
    }

    /** @return array<int, array{value:string,label:string,short_label:string,average:?float}> */
    private function monthlyAverages(SchoolYear $schoolYear, Request $request): array
    {
        $start = Carbon::parse($schoolYear->starts_on)->startOfMonth();
        $end = Carbon::parse($schoolYear->ends_on)->startOfMonth();
        $months = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            $monthStart = $cursor->copy()->startOfMonth()->max(Carbon::parse($schoolYear->starts_on));
            $monthEnd = $cursor->copy()->endOfMonth()->min(Carbon::parse($schoolYear->ends_on));

            $evaluationIds = Evaluation::query()
                ->whereHas('term', fn ($query) => $query->where('school_year_id', $schoolYear->id))
                ->whereDate('held_on', '>=', $monthStart)
                ->whereDate('held_on', '<=', $monthEnd)
                ->pluck('id');

            $grades = $this->normalizedGradeValues($evaluationIds);

            $months[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $this->monthLabel($cursor),
                'short_label' => $this->monthShortLabel($cursor),
                'average' => $grades->isNotEmpty() ? round((float) $grades->avg(), 1) : null,
            ];
        }

        return $months;
    }

    /** @return array{present_pct:float,justified_absences_pct:float,unjustified_absences_pct:float} */
    private function attendanceBreakdown(Request $request, array $period): array
    {
        $query = $this->applyPeriod(
            $this->attendanceQueryForAdminScope($request),
            'date',
            $period,
        );

        $total = (clone $query)->count();
        if ($total === 0) {
            return [
                'present_pct' => 0.0,
                'justified_absences_pct' => 0.0,
                'unjustified_absences_pct' => 0.0,
            ];
        }

        $present = (clone $query)->where('status', Attendance::STATUS_PRESENT)->count();
        $justifiedAbsences = (clone $query)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', true)
            ->count();
        $unjustifiedAbsences = (clone $query)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', false)
            ->count();
        $lates = (clone $query)->where('status', Attendance::STATUS_LATE)->count();

        $presentTotal = $present + $lates;

        return [
            'present_pct' => round(($presentTotal / $total) * 100, 1),
            'justified_absences_pct' => round(($justifiedAbsences / $total) * 100, 1),
            'unjustified_absences_pct' => round(($unjustifiedAbsences / $total) * 100, 1),
        ];
    }

    /** @return array<int, array{id:int,full_name:string,classroom:?string,average:float}> */
    private function topStudents(Request $request, ?Term $term, int $limit = 5): array
    {
        if ($term === null) {
            return [];
        }

        $studentQuery = Student::query()->with('classroom');
        AdminScopeContext::applyStudentScope($studentQuery, $request);
        SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $term->school_year_id);

        $rows = [];
        foreach ($studentQuery->get() as $student) {
            $average = $this->reportCards->compute($student, $term)['overall_average'];
            if ($average === null) {
                continue;
            }

            $rows[] = [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'classroom' => $student->classroom?->full_name,
                'average' => $average,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['average'] <=> $a['average']);

        return array_slice($rows, 0, $limit);
    }

    private function countStudentsAtRisk(Request $request, ?Term $term, float $threshold): int
    {
        if ($term === null) {
            return 0;
        }

        $studentQuery = Student::query();
        AdminScopeContext::applyStudentScope($studentQuery, $request);
        SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $term->school_year_id);

        $count = 0;
        foreach ($studentQuery->get() as $student) {
            $average = $this->reportCards->compute($student, $term)['overall_average'];
            if ($average !== null && $average < $threshold) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<int, array{type:string,severity:string,title:string,detail:string}> */
    private function buildWatchlist(
        array $classroomStats,
        Request $request,
        ?Term $term,
        array $period,
        float $threshold,
    ): array {
        $alerts = [];

        if ($term !== null) {
            $studentQuery = Student::query()->with('classroom');
            AdminScopeContext::applyStudentScope($studentQuery, $request);
            SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $term->school_year_id);

            $lowGradeAlerts = [];
            foreach ($studentQuery->get() as $student) {
                $average = $this->reportCards->compute($student, $term)['overall_average'];
                if ($average !== null && $average < $threshold) {
                    $lowGradeAlerts[] = [
                        'type' => 'low_grade',
                        'severity' => $average < ($threshold - 2) ? 'danger' : 'warn',
                        'title' => $student->full_name,
                        'detail' => sprintf('Moy. %.1f — sous le seuil de %.0f', $average, $threshold),
                        'sort' => $average,
                    ];
                }
            }

            usort($lowGradeAlerts, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            foreach (array_slice($lowGradeAlerts, 0, 3) as $alert) {
                unset($alert['sort']);
                $alerts[] = $alert;
            }
        }

        foreach ($classroomStats as $classroom) {
            if ($classroom['class_average'] !== null && $classroom['class_average'] < $threshold) {
                $alerts[] = [
                    'type' => 'class',
                    'severity' => 'warn',
                    'title' => $classroom['full_name'],
                    'detail' => sprintf(
                        'Classe sous la norme (%.1f/20)',
                        $classroom['class_average'],
                    ),
                ];
            }
        }

        $unjustifiedRows = $this->applyPeriod(
            $this->attendanceQueryForAdminScope($request)
                ->where('status', Attendance::STATUS_ABSENT)
                ->where('justified', false),
            'date',
            $period,
        )
            ->selectRaw('student_id, COUNT(*) as total')
            ->groupBy('student_id')
            ->orderByDesc('total')
            ->limit(2)
            ->get();

        $studentIds = $unjustifiedRows->pluck('student_id')->filter()->all();
        $studentsById = Student::query()->whereIn('id', $studentIds)->get()->keyBy('id');

        foreach ($unjustifiedRows as $row) {
            $student = $studentsById->get($row->student_id);
            if ($student === null || (int) $row->total < 3) {
                continue;
            }

            $alerts[] = [
                'type' => 'absences',
                'severity' => (int) $row->total >= 5 ? 'danger' : 'warn',
                'title' => $student->full_name,
                'detail' => sprintf('%d absences non justifiées', (int) $row->total),
            ];
        }

        return array_slice($alerts, 0, 5);
    }

    private function institutionAverageDelta(?SchoolYear $schoolYear, Request $request, array $period): ?float
    {
        if ($schoolYear === null || $period['starts_on'] === null || $period['ends_on'] === null) {
            return null;
        }

        $currentStart = Carbon::parse($period['starts_on']);
        $currentEnd = Carbon::parse($period['ends_on']);
        $days = max(1, $currentStart->diffInDays($currentEnd) + 1);
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1);

        $currentAverage = $this->averageForDateRange($schoolYear, $currentStart, $currentEnd);
        $previousAverage = $this->averageForDateRange($schoolYear, $previousStart, $previousEnd);

        if ($currentAverage === null || $previousAverage === null) {
            return null;
        }

        return round($currentAverage - $previousAverage, 1);
    }

    private function averageForDateRange(SchoolYear $schoolYear, Carbon $start, Carbon $end): ?float
    {
        $evaluationIds = Evaluation::query()
            ->whereHas('term', fn ($query) => $query->where('school_year_id', $schoolYear->id))
            ->whereDate('held_on', '>=', $start->toDateString())
            ->whereDate('held_on', '<=', $end->toDateString())
            ->pluck('id');

        $grades = $this->normalizedGradeValues($evaluationIds);

        return $grades->isNotEmpty() ? round((float) $grades->avg(), 1) : null;
    }
}
