<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Http\Resources\Api\V1\StudentResource;
use App\Models\Attendance;
use App\Models\ParentProfile;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Term;
use App\Support\DevCalendarContext;
use App\Services\AttendanceAlertService;
use App\Services\ParentDashboardService;
use App\Services\ReportCardService;
use App\Services\StudentTimelineService;
use App\Support\SchoolYearContext;
use App\Support\StudentProfileResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ParentPortalController extends Controller
{
    public function __construct(
        private readonly ReportCardService $reportCards,
        private readonly AttendanceAlertService $alerts,
        private readonly StudentTimelineService $timelineService,
        private readonly ParentDashboardService $parentDashboard,
    ) {}

    /** Liste des enfants rattachés au parent connecté. */
    public function children(Request $request): AnonymousResourceCollection
    {
        $profile = $this->profile($request);
        $students = $this->childrenForCurrentSchoolYear($profile, $request);

        return StudentResource::collection($students);
    }

    /** Dashboard parent : résumé par enfant. */
    public function dashboard(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $students = $this->childrenForCurrentSchoolYear($profile, $request);
        $payload = $this->parentDashboard->build($request, $students, (int) $request->user()->id);

        return response()->json($payload);
    }

    /** Périodes d'un trimestre / semestre pour un enfant. */
    public function childPeriods(Request $request, Student $student, Term $term): JsonResponse
    {
        $student = $this->authorizeChild($request, $student);
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

    /** Notes d'un enfant pour un trimestre : bulletin JSON. */
    public function childReportCard(Request $request, Student $student, Term $term): JsonResponse
    {
        $student = $this->authorizeChild($request, $student);
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

    public function childTimeline(Request $request, Student $student): JsonResponse
    {
        $student = $this->authorizeChild($request, $student);

        return response()->json([
            'data' => $this->timelineService->forStudent(
                $student,
                SchoolYearContext::requestedOrCurrent($request),
            ),
        ]);
    }

    /** Bulletin PDF d'un enfant. */
    public function childReportCardPdf(Request $request, Student $student, Term $term): Response
    {
        $student = $this->authorizeChild($request, $student);
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

    /** Absences d'un enfant. */
    public function childAttendances(Request $request, Student $student): AnonymousResourceCollection
    {
        $student = $this->authorizeChild($request, $student);

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

    /** Résumé d'assiduité d'un enfant. */
    public function childAttendanceSummary(Request $request, Student $student): JsonResponse
    {
        $student = $this->authorizeChild($request, $student);

        $absenceQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT);
        SchoolYearContext::applyDateRange($absenceQuery, $request);
        $absences = $absenceQuery->get();

        $check = $this->alerts->check($student);

        $lateQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_LATE);
        SchoolYearContext::applyDateRange($lateQuery, $request);

        return response()->json([
            'data' => [
                'student_id' => $student->id,
                'full_name' => $student->full_name,
                'total_absences' => $absences->count(),
                'unjustified' => $absences->where('justified', false)->count(),
                'justified' => $absences->where('justified', true)->count(),
                'late_count' => $lateQuery->count(),
                'alert' => $check,
            ],
        ]);
    }

    /** Confirme la justification élève d'une absence ou d'un retard. */
    public function justifyChildAttendance(Request $request, Student $student, Attendance $attendance): AttendanceResource
    {
        $student = $this->authorizeChild($request, $student);

        if ($attendance->student_id !== $student->id) {
            abort(404, 'Cette absence ne concerne pas cet élève.');
        }

        if (! in_array($attendance->status, [Attendance::STATUS_ABSENT, Attendance::STATUS_LATE], true)) {
            abort(422, 'Seules les absences et les retards peuvent être confirmés.');
        }

        if ($attendance->justified) {
            abort(422, 'Cette justification est déjà confirmée.');
        }

        if (! filled($attendance->student_justification)) {
            abort(422, 'L’élève doit d’abord soumettre sa justification avant la fin de la journée.');
        }

        SchoolYearContext::assertDateNotInArchivedYear($attendance->date?->toDateString());

        $data = $request->validate([
            'justification' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance->update([
            'justified' => true,
            'justification' => $data['justification'] ?? $attendance->student_justification,
            'justified_by' => $request->user()->id,
            'justified_at' => now(),
        ]);

        return AttendanceResource::make($attendance->fresh()->load('student', 'subject'));
    }

    /** Trimestres disponibles (tous cycles — préférer childTerms par enfant). */
    public function terms(Request $request): JsonResponse
    {
        if ($request->filled('student_id')) {
            $student = Student::query()->findOrFail($request->integer('student_id'));

            return $this->termsResponse($request, $this->authorizeChild($request, $student));
        }

        $termsQuery = Term::query()->with('schoolYear');
        SchoolYearContext::applySchoolYearColumn($termsQuery, $request);

        $terms = $termsQuery
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (Term $s) => $this->mapTermOption($s));

        return response()->json(['data' => $terms]);
    }

    /** Trimestres ou semestres du cycle de l'enfant (sélecteur bulletin). */
    public function childTerms(Request $request, Student $student): JsonResponse
    {
        $student = $this->authorizeChild($request, $student);

        return $this->termsResponse($request, $student);
    }

    private function profile(Request $request): ParentProfile
    {
        $userId = $request->user()->id;

        $profile = ParentProfile::query()->where('user_id', $userId)->first();

        if ($profile === null) {
            abort(404, 'Profil parent introuvable pour cet utilisateur.');
        }

        return $profile;
    }

    private function childrenForCurrentSchoolYear(ParentProfile $profile, Request $request)
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        $students = $profile->students()
            ->with(['classroom.level', 'classroom.schoolClass.level'])
            ->get()
            ->map(fn (Student $student) => StudentProfileResolver::forCurrentSchoolYear($student, $request))
            ->unique('id')
            ->values();

        if ($schoolYearId === null) {
            return $students;
        }

        return $students
            ->filter(fn (Student $student) => (int) $student->enrollment_school_year_id === (int) $schoolYearId)
            ->values();
    }

    private function authorizeChild(Request $request, Student $student): Student
    {
        $profile = $this->profile($request);
        $resolvedStudent = StudentProfileResolver::forCurrentSchoolYear(
            $student->loadMissing(['classroom.level', 'classroom.schoolClass.level']),
            $request,
        );
        $children = $this->childrenForCurrentSchoolYear($profile, $request);

        $isChild = $children->contains(
            fn (Student $child) => (int) $child->id === (int) $student->id
                || (int) $child->id === (int) $resolvedStudent->id
        );

        if (! $isChild) {
            abort(403, "Cet élève n'est pas rattaché à votre profil.");
        }

        return $resolvedStudent;
    }

    private function termsResponse(Request $request, Student $student): JsonResponse
    {
        $student->loadMissing(['classroom.level', 'classroom.schoolClass.level']);
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
            ->map(fn (Term $term) => $this->mapTermOption($term));

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

    /**
     * @return array<string, mixed>
     */
    private function mapTermOption(Term $term): array
    {
        return [
            'id' => $term->id,
            'name' => $term->name,
            'school_year' => $term->schoolYear?->name,
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
            'is_closed' => $term->isClosed(),
            'term_type' => $term->term_type,
            'type_label' => $term->typeLabel(),
        ];
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
            abort(403, 'Ce trimestre ne correspond pas au cycle scolaire de cet élève.');
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
}
