<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Http\Resources\Api\V1\StudentResource;
use App\Http\Resources\Api\V1\TimetableSlotResource;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Period;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Models\StudentOptionChoice;
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

        $unjustifiedAbsences = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', false)
            ->count();

        $recentGradeRows = Grade::query()
            ->where('student_id', $student->id)
            ->where('absent', false)
            ->whereHas('evaluation', fn ($q) => $q
                ->whereNotNull('published_at')
                ->where('published_at', '>=', now()->subDays(7)))
            ->with(['evaluation.subject', 'evaluation.teacher.user'])
            ->get();

        $recentGrades = $recentGradeRows
            ->sortByDesc(fn (Grade $g) => $g->evaluation?->published_at)
            ->map(function (Grade $g) {
                $eval = $g->evaluation;
                $max = (float) ($eval?->max_value ?: 20);
                $on20 = static fn (?float $v): ?float => ($v !== null && $max > 0)
                    ? round($v / $max * 20, 1)
                    : null;

                $classAvg = Grade::query()
                    ->where('evaluation_id', $eval?->id)
                    ->where('absent', false)
                    ->avg('value');

                return [
                    'id' => $g->id,
                    'subject' => $eval?->subject?->name,
                    'teacher' => $eval?->teacher?->user?->name,
                    'evaluation_name' => $eval?->name,
                    'value' => $on20((float) $g->value),
                    'class_average' => $on20($classAvg !== null ? (float) $classAvg : null),
                    'max' => 20,
                    'published_at' => $eval?->published_at?->toIso8601String(),
                ];
            })
            ->values();

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
                'unjustified_absences' => $unjustifiedAbsences,
                'recent_grades_count' => $recentGrades->count(),
                'recent_grades' => $recentGrades,
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
        $yearId = SchoolYearContext::requestedOrCurrentId($request);
        $classroomId = $this->resolveClassroomIdForYear($student, $yearId);

        $query = TimetableSlot::query()
            ->with(['subject', 'teacher.user'])
            ->where('classroom_id', $classroomId);

        if ($yearId !== null) {
            $query->where('school_year_id', $yearId);
        }

        $slots = $query->orderBy('day_of_week')->orderBy('starts_at')->get();

        return TimetableSlotResource::collection($slots);
    }

    /**
     * Résout la division de l'élève POUR l'année demandée.
     *
     * students.classroom_id n'est qu'un cache pouvant pointer vers une division
     * d'une année antérieure. On fait correspondre l'identité (niveau + option +
     * section) à la division dont la classe appartient à l'année voulue.
     */
    private function resolveClassroomIdForYear(Student $student, ?int $yearId): ?int
    {
        $base = $student->classroom()->with('schoolClass')->first();

        if ($base === null || $yearId === null) {
            return $student->classroom_id;
        }

        if ($base->schoolClass?->school_year_id === $yearId) {
            return $base->id;
        }

        $match = ClassRoom::query()
            ->where('level_id', $base->level_id)
            ->where('school_option_id', $base->school_option_id)
            ->where('section', $base->section)
            ->whereHas('schoolClass', fn ($q) => $q->where('school_year_id', $yearId))
            ->value('id');

        return $match ?? $base->id;
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

    /**
     * Formulaire de choix d'option (fin de 8e CTEB → entrée au secondaire).
     * Ouvert automatiquement, selon le calendrier, une semaine avant la fin
     * de l'année scolaire ; le choix est consommé par le passage de classe.
     */
    public function optionChoice(Request $request): JsonResponse
    {
        $student = $this->student($request)->loadMissing('classroom.level');
        $context = $this->optionChoiceContext($student);

        $choice = $context['year'] !== null
            ? StudentOptionChoice::query()
                ->where('student_id', $student->id)
                ->where('school_year_id', $context['year']->id)
                ->with('schoolOption')
                ->first()
            : null;

        $options = $context['eligible']
            ? SchoolOption::query()
                ->where(fn ($query) => $query
                    ->where('cycle', Level::CYCLE_SECONDAIRE)
                    ->orWhereNull('cycle'))
                ->orderBy('filiere')
                ->orderBy('name')
                ->get()
                ->map(fn (SchoolOption $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'abbreviation' => $option->abbreviation,
                    'filiere' => $option->filiere,
                ])
                ->values()
            : collect();

        return response()->json([
            'data' => [
                'eligible' => $context['eligible'],
                'open' => $context['open'],
                'opens_on' => $context['opens_on']?->toDateString(),
                'year_ends_on' => $context['year']?->ends_on?->toDateString(),
                'school_year' => $context['year'] ? ['id' => $context['year']->id, 'name' => $context['year']->name] : null,
                'target_level' => $context['target_level']?->name,
                'options' => $options,
                'choice' => $choice ? [
                    'school_option_id' => $choice->school_option_id,
                    'option_name' => $choice->schoolOption?->name,
                    'submitted_at' => $choice->submitted_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    /** Dépôt / modification du choix d'option pendant la fenêtre ouverte. */
    public function submitOptionChoice(Request $request): JsonResponse
    {
        $student = $this->student($request)->loadMissing('classroom.level');
        $context = $this->optionChoiceContext($student);

        if (! $context['eligible']) {
            abort(403, 'Le choix d’option ne concerne que les élèves en fin de cycle CTEB.');
        }

        if (! $context['open']) {
            abort(403, sprintf(
                'Le formulaire de choix d’option ouvre le %s (une semaine avant la clôture de l’année).',
                $context['opens_on']?->format('d/m/Y') ?? '—',
            ));
        }

        $data = $request->validate([
            'school_option_id' => ['required', 'integer', 'exists:school_options,id'],
        ]);

        $option = SchoolOption::query()->findOrFail($data['school_option_id']);
        if ($option->cycle !== null && $option->cycle !== Level::CYCLE_SECONDAIRE) {
            abort(422, 'Cette option n’appartient pas au cycle secondaire.');
        }

        $choice = StudentOptionChoice::query()->updateOrCreate(
            ['student_id' => $student->id, 'school_year_id' => $context['year']->id],
            ['school_option_id' => $option->id, 'submitted_at' => now()],
        );

        return response()->json([
            'data' => [
                'school_option_id' => $choice->school_option_id,
                'option_name' => $option->name,
                'submitted_at' => $choice->submitted_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Éligibilité et fenêtre d'ouverture du formulaire : élève dont le niveau
     * suivant est à options (8e CTEB → 1ère secondaire), à partir de 7 jours
     * avant la fin de l'année scolaire courante (non archivée).
     *
     * @return array{eligible: bool, open: bool, opens_on: ?\Carbon\CarbonInterface, year: ?SchoolYear, target_level: ?Level}
     */
    private function optionChoiceContext(Student $student): array
    {
        $level = $student->classroom?->level;
        $year = $student->enrollment_school_year_id !== null
            ? SchoolYear::query()->find($student->enrollment_school_year_id)
            : SchoolYear::query()->current()->first();

        $targetLevel = $level !== null
            ? Level::query()->where('order', '>', $level->order)->orderBy('order')->first()
            : null;

        $eligible = $year !== null
            && ! $year->isArchived()
            && $targetLevel !== null
            && (bool) $targetLevel->has_options
            && ! (bool) $level->has_options;

        $opensOn = $year?->ends_on?->copy()->subDays(StudentOptionChoice::OPEN_DAYS_BEFORE_YEAR_END);
        $open = $eligible
            && $opensOn !== null
            && DevCalendarContext::today()->toDateString() >= $opensOn->toDateString();

        return [
            'eligible' => $eligible,
            'open' => $open,
            'opens_on' => $opensOn,
            'year' => $year,
            'target_level' => $targetLevel,
        ];
    }
}
