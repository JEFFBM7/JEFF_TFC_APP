<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttendanceBatchRequest;
use App\Http\Requests\Api\V1\AttendanceJustifyRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Student;
use App\Services\AttendanceAlertService;
use App\Services\AttendanceThresholdMailer;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceAlertService $alerts,
        private readonly AttendanceThresholdMailer $thresholdMailer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Attendance::query()->with(['student', 'subject']);

        foreach (['student_id', 'classroom_id', 'subject_id'] as $key) {
            if ($request->filled($key)) {
                if ($key === 'classroom_id') {
                    AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer($key));
                }
                $query->where($key, $request->integer($key));
            }
        }
        if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
            $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }
        SchoolYearContext::applyDateRange($query, $request);
        if ($request->filled('date')) {
            $query->whereDate('date', $request->string('date')->value());
        }
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->string('from')->value());
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->string('to')->value());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return AttendanceResource::collection(
            $query->orderByDesc('date')->paginate(100),
        );
    }

    /**
     * Liste les élèves d'une classe + leur statut de présence pour une date donnée
     * (placeholder "present" si aucune entrée).
     */
    public function rollCall(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'date' => ['required', 'date'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
        ]);

        $classroomId = $request->integer('classroom_id');
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroomId);
        $subjectId = $request->filled('subject_id') ? $request->integer('subject_id') : null;
        $date = $request->string('date')->value();

        $studentsQuery = Student::query()->where('classroom_id', $classroomId);
        SchoolYearContext::applyStudentEnrollmentYear($studentsQuery, $request);
        $students = $studentsQuery
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $existing = Attendance::query()
            ->where('classroom_id', $classroomId)
            ->where('subject_id', $subjectId)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function (Student $s) use ($existing, $classroomId, $subjectId, $date): Attendance {
            $row = $existing->get($s->id) ?? new Attendance([
                'student_id' => $s->id,
                'classroom_id' => $classroomId,
                'subject_id' => $subjectId,
                'date' => $date,
                'status' => Attendance::STATUS_PRESENT,
                'justified' => false,
            ]);
            $row->setRelation('student', $s);

            return $row;
        });

        return AttendanceResource::collection($rows);
    }

    /** Saisie en lot (un appel par classe / date / cours éventuel) */
    public function saveBatch(AttendanceBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = $request->user()?->id;

        $classroomId = (int) $data['classroom_id'];
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroomId);
        $subjectId = isset($data['subject_id']) ? (int) $data['subject_id'] : null;
        $date = Carbon::parse($data['date'])->toDateString();

        // Bloque toute saisie de présence rattachée à une année scolaire archivée.
        SchoolYearContext::assertDateNotInArchivedYear($date);

        $alertedByStudentId = [];

        DB::transaction(function () use ($data, $classroomId, $subjectId, $date, $userId, &$alertedByStudentId): void {
            foreach ($data['records'] as $row) {
                $studentId = (int) $row['student_id'];
                $status = $row['status'];

                $att = Attendance::query()
                    ->where('student_id', $studentId)
                    ->whereDate('date', $date)
                    ->when(
                        $subjectId === null,
                        fn ($q) => $q->whereNull('subject_id'),
                        fn ($q) => $q->where('subject_id', $subjectId),
                    )
                    ->first();

                if ($att === null) {
                    $att = new Attendance([
                        'student_id' => $studentId,
                        'date' => $date,
                        'subject_id' => $subjectId,
                    ]);
                }
                $att->classroom_id = $classroomId;
                $att->status = $status;
                $att->created_by = $userId;

                if ($status !== Attendance::STATUS_ABSENT) {
                    $att->justified = false;
                    $att->justification = null;
                    $att->justified_by = null;
                    $att->justified_at = null;
                }

                $att->save();

                if ($att->status === Attendance::STATUS_ABSENT) {
                    $student = Student::find($att->student_id);
                    if ($student) {
                        $check = $this->alerts->check($student);
                        if ($check['triggered']) {
                            $alertedByStudentId[$student->id] = [
                                'student_id' => $student->id,
                                'full_name' => $student->full_name,
                                'reasons' => array_values($check['reasons']),
                                'consecutive' => $check['consecutive'],
                                'last_30d' => $check['count_recent_30d'],
                            ];
                        }
                    }
                }
            }
        });

        $alertedStudents = array_values($alertedByStudentId);

        foreach ($alertedStudents as $alertRow) {
            $student = Student::query()->find($alertRow['student_id']);
            if ($student !== null) {
                $this->thresholdMailer->notifyParentsIfNeeded($student, $alertRow);
            }
        }

        return response()->json([
            'message' => 'Présences enregistrées.',
            'alerts' => $alertedStudents,
        ]);
    }

    public function justify(AttendanceJustifyRequest $request, Attendance $attendance): AttendanceResource
    {
        AdminScopeContext::assertClassroomAllowed($request->user(), $attendance->classroom_id);
        SchoolYearContext::assertDateNotInArchivedYear($attendance->date?->toDateString());

        $data = $request->validated();
        $attendance->update([
            'justified' => $data['justified'],
            'justification' => $data['justification'] ?? null,
            'justified_by' => $request->user()?->id,
            'justified_at' => $data['justified'] ? now() : null,
        ]);

        return AttendanceResource::make($attendance->fresh()->load('student', 'subject'));
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $attendance->classroom_id);
        SchoolYearContext::assertDateNotInArchivedYear($attendance->date?->toDateString());

        $attendance->delete();

        return response()->json(null, 204);
    }

    /** Statistiques d'absences pour un élève (CDC §4.5 récap par élève) */
    public function studentSummary(Request $request, Student $student): JsonResponse
    {
        AdminScopeContext::assertStudentAllowed($request->user(), $student);

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

    /** Récap d'une classe sur une période */
    public function classSummary(ClassRoom $classroom, Request $request): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed($request->user(), $classroom);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $q = Attendance::query()->where('classroom_id', $classroom->id);
        SchoolYearContext::applyDateRange($q, $request);
        if ($request->filled('from')) {
            $q->whereDate('date', '>=', $request->string('from')->value());
        }
        if ($request->filled('to')) {
            $q->whereDate('date', '<=', $request->string('to')->value());
        }

        $rows = $q->get();

        return response()->json([
            'data' => [
                'classroom_id' => $classroom->id,
                'total' => $rows->count(),
                'absent' => $rows->where('status', Attendance::STATUS_ABSENT)->count(),
                'late' => $rows->where('status', Attendance::STATUS_LATE)->count(),
                'present' => $rows->where('status', Attendance::STATUS_PRESENT)->count(),
                'unjustified_absences' => $rows->where('status', Attendance::STATUS_ABSENT)->where('justified', false)->count(),
            ],
        ]);
    }
}
