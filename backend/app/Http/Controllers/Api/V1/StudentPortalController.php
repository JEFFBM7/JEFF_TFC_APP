<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Http\Resources\Api\V1\StudentResource;
use App\Http\Resources\Api\V1\TimetableSlotResource;
use App\Models\Attendance;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\Student;
use App\Models\TimetableSlot;
use App\Services\AttendanceAlertService;
use App\Services\ReportCardService;
use App\Services\StudentTimelineService;
use App\Support\DevCalendarContext;
use App\Support\SchoolYearContext;
use App\Support\StudentProfileResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portail élève (CDC §4.1 ligne 101 — « consultation de ses notes, bulletins et emploi du temps »).
 */
class StudentPortalController extends Controller
{
    public function __construct(
        private readonly ReportCardService $reportCards,
        private readonly AttendanceAlertService $alerts,
        private readonly StudentTimelineService $timelineService,
    ) {}

    /** Profil + classe de l'élève connecté. */
    public function me(Request $request): JsonResponse
    {
        $student = $this->student($request);

        return response()->json([
            'data' => StudentResource::make($student->load('classroom.level'))->resolve(),
        ]);
    }

    /** Dashboard : résumé note + absences du trimestre en cours. */
    public function dashboard(Request $request): JsonResponse
    {
        $student = $this->student($request)->loadMissing(['classroom.level', 'classroom.schoolClass.level']);
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);

        $currentTerm = $schoolYear !== null
            ? $this->resolveCurrentTermForStudent($schoolYear, $student)
            : null;

        $average = null;
        if ($currentTerm) {
            $report = $this->reportCards->compute($student, $currentTerm, true);
            $average = $report['overall_average'];
        }

        $absenceQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT);
        SchoolYearContext::applyDateRange($absenceQuery, $request);
        $absences = $absenceQuery->count();

        $lateQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_LATE);
        SchoolYearContext::applyDateRange($lateQuery, $request);
        $lates = $lateQuery->count();

        $check = $this->alerts->check($student);

        return response()->json([
            'data' => [
                'student_id' => $student->id,
                'full_name' => $student->full_name,
                'first_name' => $student->first_name,
                'classroom' => $student->classroom?->full_name,
                'current_term' => $currentTerm?->name,
                'current_average' => $average,
                'total_absences' => $absences,
                'total_lates' => $lates,
                'alert' => $check,
            ],
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        $student = $this->student($request);

        return response()->json([
            'data' => $this->timelineService->forStudent(
                $student,
                SchoolYearContext::requestedOrCurrent($request),
            ),
        ]);
    }

    /** Bulletin JSON du trimestre donné. */
    public function reportCard(Request $request, Term $term): JsonResponse
    {
        $student = $this->student($request);
        $this->assertTermMatchesStudentCycle($student, $term);
        $scopePeriod = $this->resolveScopedPeriod($request, $term);
        $report = $this->reportCards->compute($student, $term, true, $scopePeriod);

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'registration_number' => $student->registration_number,
                    'classroom' => $student->classroom?->full_name,
                ],
                'term' => ['id' => $term->id, 'name' => $term->name],
                'subjects' => $report['subjects']->map(fn ($r) => [
                    'subject_id' => $r['subject']->id,
                    'subject_name' => $r['subject']->name,
                    'coefficient' => (float) $r['coefficient'],
                    'count' => (int) $r['count'],
                    'average' => $r['average'],
                    'evaluations' => $r['evaluations'] ?? [],
                ])->values(),
                'period_averages' => $report['period_averages']->map(fn ($r) => [
                    'period_id' => $r['period']->id,
                    'name' => $r['period']->name,
                    'position' => $r['period']->position,
                    'average' => $r['average'],
                ])->values(),
                'overall_average' => $report['overall_average'],
                'total_coefficient' => $report['total_coefficient'],
                'appreciation' => $report['appreciation'] ?? null,
                'scoped_period_id' => $report['scoped_period_id'],
            ],
        ]);
    }

    /** Périodes d'un trimestre / semestre. */
    public function periods(Request $request, Term $term): JsonResponse
    {
        $student = $this->student($request);
        $this->assertTermMatchesStudentCycle($student, $term);

        $periods = $term->periods()
            ->orderBy('position')
            ->get()
            ->map(fn (Period $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'position' => $period->position,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'is_closed' => $period->isClosed(),
            ]);

        $recommended = DevCalendarContext::resolvePeriod($term);

        return response()->json([
            'data' => $periods,
            'meta' => [
                'term_id' => $term->id,
                'term_type' => $term->term_type,
                'term_type_label' => $term->typeLabel(),
                'recommended_period_id' => $recommended?->id,
            ],
        ]);
    }

    /** Bulletin PDF. */
    public function reportCardPdf(Request $request, Term $term): Response
    {
        $student = $this->student($request);
        $this->assertTermMatchesStudentCycle($student, $term);
        $scopePeriod = $this->resolveScopedPeriod($request, $term);
        $report = $this->reportCards->compute($student, $term, true, $scopePeriod);
        $term->loadMissing('schoolYear');

        $pdf = Pdf::loadView('report_cards.pdf', [
            'student' => $student->loadMissing('classroom.level'),
            'term' => $term,
            'subjects' => $report['subjects'],
            'period_averages' => $report['period_averages'],
            'overall_average' => $report['overall_average'],
            'appreciation' => $report['appreciation'] ?? null,
        ])->setPaper('a4');

        $filename = sprintf(
            'bulletin-%s-%s.pdf',
            Str::slug($student->full_name),
            Str::slug($term->name),
        );

        return $pdf->download($filename);
    }

    /** Historique des absences de l'élève. */
    public function attendances(Request $request): AnonymousResourceCollection
    {
        $student = $this->student($request);

        $query = Attendance::query()
            ->where('student_id', $student->id)
            ->with(['subject'])
            ->orderByDesc('date');
        SchoolYearContext::applyDateRange($query, $request);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        } else {
            $query->whereIn('status', [Attendance::STATUS_ABSENT, Attendance::STATUS_LATE]);
        }

        return AttendanceResource::collection($query->paginate(50));
    }

    /** Justification initiale par l'élève, à confirmer ensuite par son responsable. */
    public function justifyAttendance(Request $request, Attendance $attendance): AttendanceResource
    {
        $student = $this->student($request);

        if ($attendance->student_id !== $student->id) {
            abort(404, 'Cette présence ne concerne pas votre profil élève.');
        }

        if (! in_array($attendance->status, [Attendance::STATUS_ABSENT, Attendance::STATUS_LATE], true)) {
            abort(422, 'Seules les absences et les retards peuvent être justifiés.');
        }

        if ($attendance->justified) {
            abort(422, 'Cette présence est déjà confirmée par le responsable.');
        }

        if ($attendance->date === null || $attendance->date->copy()->endOfDay()->lessThan(now())) {
            abort(422, 'Le délai de justification élève est dépassé : elle doit être envoyée avant la fin de la journée.');
        }

        SchoolYearContext::assertDateNotInArchivedYear($attendance->date?->toDateString());

        $data = $request->validate([
            'justification' => ['required', 'string', 'max:500'],
        ]);

        $attendance->update([
            'student_justification' => $data['justification'],
            'student_justified_at' => now(),
        ]);

        return AttendanceResource::make($attendance->fresh()->load('student', 'subject'));
    }

    /** Emploi du temps de la classe de l'élève pour une année scolaire. */
    public function timetable(Request $request): AnonymousResourceCollection
    {
        $student = $this->student($request);

        $query = TimetableSlot::query()
            ->with(['subject', 'teacher.user'])
            ->where('classroom_id', $student->classroom_id);

        SchoolYearContext::applySchoolYearColumn($query, $request);

        $slots = $query->orderBy('day_of_week')->orderBy('starts_at')->get();

        return TimetableSlotResource::collection($slots);
    }

    /** Trimestres disponibles (cycle de la classe de l'élève uniquement). */
    public function terms(Request $request): JsonResponse
    {
        $student = $this->student($request)->loadMissing(['classroom.level', 'classroom.schoolClass.level']);
        $schoolYear = SchoolYearContext::requestedOrCurrent($request);

        $termsQuery = Term::query()->with('schoolYear');
        if ($schoolYear !== null) {
            $termsQuery->where('school_year_id', $schoolYear->id);
        } else {
            SchoolYearContext::applySchoolYearColumn($termsQuery, $request);
        }

        $applicableCycle = Term::applicableCycleForLevelCycle(
            $student->classroom?->level?->cycle ?? $student->classroom?->schoolClass?->level?->cycle,
        );
        $termsQuery->where('applicable_cycle', $applicableCycle);

        $terms = $termsQuery
            ->orderBy('position')
            ->get()
            ->map(fn (Term $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'school_year' => $s->schoolYear?->name,
                'starts_on' => $s->starts_on->toDateString(),
                'ends_on' => $s->ends_on->toDateString(),
                'is_closed' => $s->isClosed(),
                'term_type' => $s->term_type,
                'type_label' => $s->typeLabel(),
            ]);

        $recommended = $schoolYear !== null
            ? $this->resolveCurrentTermForStudent($schoolYear, $student)
            : null;

        return response()->json([
            'data' => $terms,
            'meta' => [
                'recommended_term_id' => $recommended?->id,
                'applicable_cycle' => $applicableCycle,
            ],
        ]);
    }

    /** Années scolaires disponibles. */
    public function schoolYears(): JsonResponse
    {
        $years = SchoolYear::query()
            ->orderByDesc('starts_on')
            ->get(['id', 'name', 'is_current']);

        return response()->json(['data' => $years]);
    }

    private function resolveScopedPeriod(Request $request, Term $term): ?Period
    {
        if (! $request->filled('period_id')) {
            return null;
        }

        $period = Period::query()
            ->whereKey($request->integer('period_id'))
            ->where('term_id', $term->id)
            ->first();

        if ($period === null) {
            abort(422, 'Période invalide pour ce trimestre ou semestre.');
        }

        return $period;
    }

    private function assertTermMatchesStudentCycle(Student $student, Term $term): void
    {
        $student->loadMissing(['classroom.level', 'classroom.schoolClass.level']);
        $applicableCycle = Term::applicableCycleForLevelCycle(
            $student->classroom?->level?->cycle ?? $student->classroom?->schoolClass?->level?->cycle,
        );

        if ($term->applicable_cycle !== $applicableCycle) {
            abort(403, 'Ce trimestre ne correspond pas à votre cycle scolaire.');
        }
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

    private function student(Request $request): Student
    {
        $userId = $request->user()->id;

        $student = Student::query()
            ->where('user_id', $userId)
            ->with('classroom.level')
            ->first();

        if ($student === null) {
            abort(404, "Aucun profil élève associé à ce compte utilisateur.");
        }

        $student = StudentProfileResolver::forCurrentSchoolYear($student, $request);

        return $student;
    }
}
