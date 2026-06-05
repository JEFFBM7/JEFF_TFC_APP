<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Message;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Term;
use App\Support\DevCalendarContext;
use App\Support\SchoolYearContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ParentDashboardService
{
    public function __construct(
        private readonly ReportCardService $reportCards,
        private readonly AttendanceAlertService $alerts,
    ) {}

    /**
     * @param  Collection<int, Student>  $students
     * @return array{data: list<array<string, mixed>>, unread_messages: int}
     */
    public function build(Request $request, Collection $students, int $parentUserId): array
    {
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);
        $today = DevCalendarContext::today();

        $summaries = $students
            ->map(fn (Student $student) => $this->buildChildSummary($student, $request, $schoolYear, $today))
            ->values()
            ->all();

        return [
            'data' => $summaries,
            'unread_messages' => Message::query()
                ->where('recipient_id', $parentUserId)
                ->where('deleted_by_recipient', false)
                ->whereNull('read_at')
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChildSummary(
        Student $student,
        Request $request,
        ?SchoolYear $schoolYear,
        CarbonImmutable $today,
    ): array {
        $student->loadMissing(['classroom.level', 'classroom.schoolClass.level']);

        $currentTerm = $schoolYear !== null
            ? $this->resolveCurrentTermForStudent($schoolYear, $student)
            : null;

        $currentAverage = null;
        $appreciation = null;
        if ($currentTerm !== null) {
            $report = $this->reportCards->compute($student, $currentTerm, true);
            $currentAverage = $report['overall_average'];
            $appreciation = $report['appreciation'] ?? null;
        }

        $previousAverage = $this->previousTermAverage($student, $schoolYear, $currentTerm);
        $trend = $this->resolveTrend($currentAverage, $previousAverage);
        $status = $this->wellbeingStatus($currentAverage);

        $classRank = null;
        $classSize = 0;
        if ($currentTerm !== null && $student->classroom_id !== null) {
            $ranking = $this->reportCards->classRanking($student->classroom_id, $currentTerm, true);
            $classSize = $ranking->count();
            $position = $ranking->search(
                fn (array $row) => (int) $row['student']->id === (int) $student->id,
            );
            $classRank = $position === false ? null : $position + 1;
        }

        $absenceQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT);
        SchoolYearContext::applyDateRange($absenceQuery, $request);
        $totalAbsences = $absenceQuery->count();

        $lateQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_LATE);
        SchoolYearContext::applyDateRange($lateQuery, $request);
        $totalLates = $lateQuery->count();

        $recentAbsences = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', false)
            ->with('subject:id,name')
            ->orderByDesc('date')
            ->limit(5)
            ->get()
            ->map(fn (Attendance $attendance) => [
                'id' => $attendance->id,
                'date' => $attendance->date?->toDateString(),
                'justified' => (bool) $attendance->justified,
                'subject' => $attendance->subject?->name,
            ])
            ->values()
            ->all();

        $recentLatesCount = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_LATE)
            ->whereBetween('date', [
                $today->startOfMonth()->toDateString(),
                $today->endOfMonth()->toDateString(),
            ])
            ->count();

        $gradesSince = $today->subDays(14);
        $newGrades = Grade::query()
            ->where('grades.student_id', $student->id)
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereNotNull('evaluations.published_at')
            ->where('evaluations.published_at', '>=', $gradesSince)
            ->when($student->classroom_id, fn ($query) => $query
                ->where('evaluations.classroom_id', $student->classroom_id))
            ->with(['evaluation.subject:id,name'])
            ->orderByDesc('evaluations.published_at')
            ->select('grades.*')
            ->limit(5)
            ->get()
            ->map(fn (Grade $grade) => [
                'evaluation_id' => $grade->evaluation_id,
                'subject_name' => $grade->evaluation?->subject?->name,
                'evaluation_name' => $grade->evaluation?->name,
                'type_label' => Evaluation::typeLabel($grade->evaluation?->type),
                'held_on' => $grade->evaluation?->held_on?->toDateString(),
                'value' => $grade->value === null ? null : (float) $grade->value,
                'max_value' => $grade->evaluation?->max_value === null
                    ? 20.0
                    : (float) $grade->evaluation->max_value,
            ])
            ->values()
            ->all();

        $justificationDeadlines = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', false)
            ->whereNotNull('student_justification')
            ->orderByDesc('date')
            ->limit(5)
            ->get()
            ->map(fn (Attendance $attendance) => [
                'attendance_id' => $attendance->id,
                'date' => $attendance->date?->toDateString(),
                'label' => 'Confirmation parent attendue',
            ])
            ->values()
            ->all();

        $upcomingEvaluations = [];
        if ($student->classroom_id !== null) {
            $upcomingEvaluations = Evaluation::query()
                ->where('classroom_id', $student->classroom_id)
                ->whereDate('held_on', '>=', $today)
                ->with(['subject:id,name', 'term:id,name'])
                ->orderBy('held_on')
                ->limit(5)
                ->get()
                ->map(fn (Evaluation $evaluation) => [
                    'id' => $evaluation->id,
                    'name' => $evaluation->name,
                    'type_label' => Evaluation::typeLabel($evaluation->type),
                    'held_on' => $evaluation->held_on?->toDateString(),
                    'subject_name' => $evaluation->subject?->name,
                    'term_name' => $evaluation->term?->name,
                ])
                ->values()
                ->all();
        }

        $reportCards = $this->availableReportCards($student, $schoolYear);

        return [
            'student_id' => $student->id,
            'first_name' => $student->first_name,
            'full_name' => $student->full_name,
            'classroom' => $student->classroom?->full_name,
            'total_absences' => $totalAbsences,
            'total_lates' => $totalLates,
            'current_average' => $currentAverage,
            'current_term' => $currentTerm?->name,
            'current_term_id' => $currentTerm?->id,
            'wellbeing' => [
                'average' => $currentAverage,
                'previous_average' => $previousAverage,
                'trend' => $trend,
                'term_name' => $currentTerm?->name,
                'term_type_label' => $currentTerm?->typeLabel(),
                'class_rank' => $classRank,
                'class_size' => $classSize,
                'appreciation' => $appreciation,
                'status' => $status,
            ],
            'recent' => [
                'attendance_alert' => $this->alerts->check($student, $today),
                'unjustified_absences_count' => Attendance::query()
                    ->where('student_id', $student->id)
                    ->where('status', Attendance::STATUS_ABSENT)
                    ->where('justified', false)
                    ->when($schoolYear !== null, fn ($query) => SchoolYearContext::applyYearDateRange($query, $schoolYear))
                    ->count(),
                'recent_absences' => $recentAbsences,
                'recent_lates_count' => $recentLatesCount,
                'new_grades' => $newGrades,
            ],
            'upcoming' => [
                'evaluations' => $upcomingEvaluations,
                'justification_deadlines' => $justificationDeadlines,
                'report_cards' => $reportCards,
            ],
        ];
    }

    private function resolveCurrentTermForStudent(SchoolYear $schoolYear, Student $student): ?Term
    {
        $levelCycle = $student->classroom?->level?->cycle
            ?? $student->classroom?->schoolClass?->level?->cycle;
        $applicableCycle = Term::applicableCycleForLevelCycle($levelCycle);

        $current = DevCalendarContext::resolveTerm($schoolYear, $applicableCycle);
        if ($current !== null) {
            return $current;
        }

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

    private function previousTermAverage(Student $student, ?SchoolYear $schoolYear, ?Term $currentTerm): ?float
    {
        if ($schoolYear === null || $currentTerm === null) {
            return null;
        }

        $previousTerm = Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->where('applicable_cycle', $currentTerm->applicable_cycle)
            ->where('position', '<', $currentTerm->position)
            ->orderByDesc('position')
            ->first();

        if ($previousTerm === null) {
            return null;
        }

        return $this->reportCards->compute($student, $previousTerm, true)['overall_average'];
    }

    private function resolveTrend(?float $current, ?float $previous): ?string
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if ($current > $previous + 0.2) {
            return 'up';
        }

        if ($current < $previous - 0.2) {
            return 'down';
        }

        return 'stable';
    }

    private function wellbeingStatus(?float $average): string
    {
        if ($average === null) {
            return 'unknown';
        }

        if ($average < 8) {
            return 'concern';
        }

        if ($average < 10) {
            return 'watch';
        }

        return 'good';
    }

    /**
     * @return list<array{term_id: int, name: string, type_label: string|null}>
     */
    private function availableReportCards(Student $student, ?SchoolYear $schoolYear): array
    {
        if ($schoolYear === null) {
            return [];
        }

        $levelCycle = $student->classroom?->level?->cycle
            ?? $student->classroom?->schoolClass?->level?->cycle;
        $applicableCycle = Term::applicableCycleForLevelCycle($levelCycle);

        return Term::query()
            ->where('school_year_id', $schoolYear->id)
            ->where('applicable_cycle', $applicableCycle)
            ->orderBy('position')
            ->get()
            ->filter(function (Term $term) use ($student) {
                $average = $this->reportCards->compute($student, $term, true)['overall_average'];

                return $average !== null;
            })
            ->map(fn (Term $term) => [
                'term_id' => $term->id,
                'name' => $term->name,
                'type_label' => $term->typeLabel(),
            ])
            ->values()
            ->all();
    }
}
