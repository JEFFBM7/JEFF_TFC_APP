<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Services\AttendanceAlertService;
use App\Services\LowGradeAlertService;
use App\Services\ReportCardService;
use App\Services\StudentPortalAccountService;
use App\Support\AdminScopeContext;
use App\Support\DevCalendarContext;
use App\Support\SchoolYearContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste des élèves dépassant un ou plusieurs seuils d'alerte (CDC §4.7).
 *
 * Filtres :
 *   - type=absences|lates|low_grade|all (défaut all)
 *   - term_id=<id> (sinon trimestre courant ou dernier en date)
 *   - classroom_id=<id> (optionnel)
 */
class StudentsAtRiskController extends Controller
{
    public function __construct(
        private readonly AttendanceAlertService $alerts,
        private readonly LowGradeAlertService $lowGrade,
        private readonly ReportCardService $reportCards,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->string('type')->value() ?: 'all';
        if ($request->filled('cycle') && ! AdminScopeContext::requestedCycleIsAllowed($request)) {
            abort(403, 'Ce cycle est hors de votre périmètre administratif.');
        }
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $termsByCycle = $this->resolveTermsByCycle($request, $schoolYear);
        $classroomId = $request->filled('classroom_id') ? $request->integer('classroom_id') : null;

        $studentQuery = Student::query()->with(['classroom.level', 'classroom.schoolClass.level']);
        AdminScopeContext::applyStudentScope($studentQuery, $request);
        if ($request->filled('cycle')) {
            $cycle = $request->string('cycle')->value();
            $studentQuery->whereHas(
                'classroom',
                fn ($classroomQuery) => AdminScopeContext::applyClassroomCycleScope($classroomQuery, [$cycle]),
            );
        }
        if ($schoolYear !== null) {
            SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $schoolYear->id);
        }
        if ($classroomId !== null) {
            AdminScopeContext::assertClassroomAllowed($request->user(), $classroomId);
            $studentQuery->where('classroom_id', $classroomId);
        }
        $this->applyTeacherScope($studentQuery, $request, $schoolYear?->id);
        $students = $studentQuery
            ->with(['parents.user', 'user', 'classroom.level'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $today = CarbonImmutable::today();
        $threshold = $this->lowGrade->threshold();
        $portalAccounts = app(StudentPortalAccountService::class);

        $rows = [];
        foreach ($students as $student) {
            $absencesAlert = $this->alerts->check($student, $today);
            $hasAbsence = in_array(
                AttendanceAlertService::REASON_CONSECUTIVE,
                $absencesAlert['reasons'],
                true,
            ) || in_array(
                AttendanceAlertService::REASON_ROLLING,
                $absencesAlert['reasons'],
                true,
            );
            $hasLate = in_array(AttendanceAlertService::REASON_LATE, $absencesAlert['reasons'], true);

            $average = null;
            $hasLowGrade = false;
            $gradeTerm = $this->termForStudent($student, $termsByCycle);
            if ($gradeTerm !== null) {
                $report = $this->reportCards->compute($student, $gradeTerm);
                $average = $report['overall_average'];
                $hasLowGrade = $average !== null && $average < $threshold;
            }

            $matches = match ($type) {
                'absences' => $hasAbsence,
                'lates' => $hasLate,
                'low_grade' => $hasLowGrade,
                default => $hasAbsence || $hasLate || $hasLowGrade,
            };

            if (! $matches) {
                continue;
            }

            $parentUsers = $student->parents
                ->filter(fn ($parent) => $parent->user !== null)
                ->map(fn ($parent) => [
                    'id' => $parent->user->id,
                    'name' => $parent->user->name,
                ])
                ->values()
                ->all();

            $rows[] = [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'classroom' => $student->classroom?->full_name,
                'classroom_id' => $student->classroom_id,
                'level' => $student->classroom?->level?->name,
                'cycle' => $student->classroom?->level?->cycle
                    ?? $student->classroom?->schoolClass?->level?->cycle,
                'grade_term' => $gradeTerm ? [
                    'id' => $gradeTerm->id,
                    'name' => $gradeTerm->name,
                    'type_label' => $gradeTerm->typeLabel(),
                ] : null,
                'student_user_id' => $student->user_id,
                'student_portal_active' => $portalAccounts->status($student) === StudentPortalAccountService::STATUS_ACTIVE,
                'parent_users' => $parentUsers,
                'triggers' => [
                    'absences_consecutive' => $absencesAlert['consecutive'],
                    'absences_rolling' => $absencesAlert['count_recent_30d'],
                    'lates' => $absencesAlert['late_count'],
                    'has_absence_alert' => $hasAbsence,
                    'has_late_alert' => $hasLate,
                    'has_low_grade_alert' => $hasLowGrade,
                ],
                'average' => $average,
            ];
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'thresholds' => [
                    'consecutive' => (int) AppSetting::get('attendance.consecutive_threshold', 3),
                    'rolling' => (int) AppSetting::get('attendance.rolling_threshold', 5),
                    'rolling_window_days' => (int) AppSetting::get('attendance.rolling_window_days', 30),
                    'late' => (int) AppSetting::get('attendance.late_threshold', 5),
                    'late_window_days' => (int) AppSetting::get('attendance.late_window_days', 30),
                    'low_grade' => $threshold,
                ],
                'term' => $this->legacyMetaTerm($termsByCycle),
                'terms' => $this->metaTermsPayload($termsByCycle),
                'type' => $type,
                'admin_scope' => $request->user()?->role === UserRole::Admin
                    ? ($request->user()->admin_scope ?: AdminScopeContext::GLOBAL)
                    : null,
                'admin_scope_label' => AdminScopeContext::scopeLabel($request->user()),
                'admin_cycles' => AdminScopeContext::allowedCycles($request->user()),
            ],
        ]);
    }

    /**
     * Termes utilisés pour le calcul des moyennes, indexés par applicable_cycle (primaire / secondaire).
     *
     * @return array<string, Term>
     */
    private function resolveTermsByCycle(Request $request, ?SchoolYear $schoolYear): array
    {
        if ($schoolYear === null) {
            return [];
        }

        if ($request->filled('term_id')) {
            $term = Term::query()->find($request->integer('term_id'));
            if ($term === null) {
                return [];
            }
            AdminScopeContext::assertTermApplicableCycleAllowed($request->user(), $term);

            return [$term->applicable_cycle => $term];
        }

        $allowed = AdminScopeContext::allowedTermApplicableCycles($request->user());
        if (is_array($allowed) && $allowed === []) {
            return [];
        }

        $applicableCycles = $allowed ?? [Term::CYCLE_PRIMAIRE, Term::CYCLE_SECONDAIRE];
        $terms = [];

        foreach ($applicableCycles as $applicableCycle) {
            $term = DevCalendarContext::resolveTerm($schoolYear, $applicableCycle)
                ?? $this->resolveFallbackTerm($schoolYear, $applicableCycle);

            if ($term !== null) {
                $terms[$applicableCycle] = $term;
            }
        }

        return $terms;
    }

    /**
     * @param  array<string, Term>  $termsByCycle
     */
    private function termForStudent(Student $student, array $termsByCycle): ?Term
    {
        if ($termsByCycle === []) {
            return null;
        }

        $levelCycle = $student->classroom?->level?->cycle
            ?? $student->classroom?->schoolClass?->level?->cycle;

        if (! is_string($levelCycle) || $levelCycle === '') {
            return null;
        }

        $applicableCycle = Term::applicableCycleForLevelCycle($levelCycle);

        return $termsByCycle[$applicableCycle] ?? null;
    }

    private function resolveFallbackTerm(SchoolYear $schoolYear, string $applicableCycle): ?Term
    {
        $today = DevCalendarContext::today();
        $scoped = Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->where('applicable_cycle', $applicableCycle);

        $next = (clone $scoped)
            ->whereDate('starts_on', '>', $today)
            ->orderBy('starts_on')
            ->first();
        if ($next !== null) {
            return $next;
        }

        return $scoped
            ->whereDate('ends_on', '<', $today)
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * @param  array<string, Term>  $termsByCycle
     * @return array<string, array{id: int, name: string, term_type: string|null, type_label: string, applicable_cycle: string}>|null
     */
    private function metaTermsPayload(array $termsByCycle): ?array
    {
        if ($termsByCycle === []) {
            return null;
        }

        $payload = [];
        foreach ($termsByCycle as $applicableCycle => $term) {
            $payload[$applicableCycle] = [
                'id' => $term->id,
                'name' => $term->name,
                'term_type' => $term->term_type,
                'type_label' => $term->typeLabel(),
                'applicable_cycle' => $applicableCycle,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, Term>  $termsByCycle
     * @return array{id: int, name: string, term_type: string|null, type_label: string}|null
     */
    private function legacyMetaTerm(array $termsByCycle): ?array
    {
        $term = $termsByCycle[Term::CYCLE_PRIMAIRE]
            ?? $termsByCycle[Term::CYCLE_SECONDAIRE]
            ?? reset($termsByCycle);

        if ($term === false || $term === null) {
            return null;
        }

        return [
            'id' => $term->id,
            'name' => $term->name,
            'term_type' => $term->term_type,
            'type_label' => $term->typeLabel(),
        ];
    }

    private function applyTeacherScope($studentQuery, Request $request, ?int $schoolYearId): void
    {
        $user = $request->user();
        if ($user?->role !== UserRole::Enseignant) {
            return;
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher === null) {
            $studentQuery->whereRaw('1 = 0');

            return;
        }

        $classroomIds = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->when($schoolYearId !== null, fn ($query) => $query->where('school_year_id', $schoolYearId))
            ->distinct()
            ->pluck('classroom_id')
            ->filter()
            ->values();

        if ($classroomIds->isEmpty()) {
            $studentQuery->whereRaw('1 = 0');

            return;
        }

        $studentQuery->whereIn('classroom_id', $classroomIds);
    }
}
