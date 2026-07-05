<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EvaluationRequest;
use App\Http\Requests\Api\V1\GradeBatchRequest;
use App\Http\Resources\Api\V1\EvaluationResource;
use App\Http\Resources\Api\V1\GradeResource;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\GradeAudit;
use App\Models\Period;
use App\Models\Term;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EvaluationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Evaluation::query()
            ->with(['classroom.level', 'subject', 'period'])
            ->withCount('grades');

        foreach (['classroom_id', 'subject_id', 'term_id', 'period_id', 'teacher_id'] as $key) {
            if ($request->filled($key)) {
                if ($key === 'classroom_id') {
                    AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer($key));
                }
                $query->where($key, $request->integer($key));
            }
        }

        SchoolYearContext::applyEvaluationSchoolYear($query, $request);
        $this->applyEvaluationAccessScope($query, $request);

        // Compteurs globaux (toutes pages confondues) pour les cartes de
        // résumé et les onglets — sur le même périmètre classe/cours/terme/
        // période que la liste, mais AVANT le filtre d'onglet lui-même
        // (sinon les autres onglets retomberaient toujours à 0).
        // Un brouillon (non publié) reste un brouillon quel que soit son type :
        // les trois compteurs se partagent le total sans se chevaucher.
        $summary = [
            'total' => (clone $query)->count(),
            'exams' => (clone $query)->whereNotNull('published_at')->where('type', Evaluation::TYPE_EXAMEN)->count(),
            'continuous' => (clone $query)->whereNotNull('published_at')->where('type', '!=', Evaluation::TYPE_EXAMEN)->count(),
            'drafts' => (clone $query)->whereNull('published_at')->count(),
        ];

        // Onglets Toutes/Examens/Contrôle continu/Brouillons : filtrés côté
        // serveur (comme classroom_id, subject_id...) pour rester corrects
        // avec la pagination — un filtre appliqué seulement sur la page
        // chargée ignorerait les correspondances des autres pages.
        match ($request->string('component')->value()) {
            'exam' => $query->whereNotNull('published_at')->where('type', Evaluation::TYPE_EXAMEN),
            'continuous' => $query->whereNotNull('published_at')->where('type', '!=', Evaluation::TYPE_EXAMEN),
            'draft' => $query->whereNull('published_at'),
            default => null,
        };

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50);

        return EvaluationResource::collection($paginator)->additional(['summary' => $summary]);
    }

    public function store(EvaluationRequest $request): JsonResponse
    {
        $period = Period::query()->with('term')->findOrFail($request->integer('period_id'));
        $term = $period->term;
        $this->assertPeriodIsWritable($period);
        SchoolYearContext::assertTermNotArchived($term);

        $data = $request->validated();
        $data['term_id'] = $period->term_id;
        AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);

        if ($request->user()?->hasRole('enseignant')) {
            $teacher = Teacher::query()->where('user_id', $request->user()->id)->first();
            if ($teacher === null || ! $this->teacherCanManageContext($teacher, (int) $data['classroom_id'], (int) $data['subject_id'], $term)) {
                abort(403, 'Vous ne pouvez créer une évaluation que pour vos propres affectations.');
            }

            $data['teacher_id'] = $teacher->id;
        }

        $evaluation = Evaluation::query()->create($data);

        return EvaluationResource::make($evaluation->load(['classroom.level', 'subject', 'period']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Evaluation $evaluation): EvaluationResource
    {
        $this->authorizeEvaluationAccess($evaluation);

        return EvaluationResource::make(
            $evaluation->load(['classroom.level', 'subject', 'period'])->loadCount('grades'),
        );
    }

    public function update(EvaluationRequest $request, Evaluation $evaluation): EvaluationResource
    {
        $this->authorizeEvaluationAccess($evaluation);
        $this->assertRoleCanManageEvaluationType($evaluation);

        $currentTerm = $evaluation->term()->first();
        if ($currentTerm) {
            SchoolYearContext::assertTermNotArchived($currentTerm);
        }
        $period = Period::query()->with('term')->findOrFail($request->integer('period_id'));
        $newTerm = $period->term;
        $this->assertPeriodIsWritable($period);
        SchoolYearContext::assertTermNotArchived($newTerm);

        $data = $request->validated();
        $data['term_id'] = $period->term_id;
        AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);

        if ($request->user()?->hasRole('enseignant')) {
            $teacher = Teacher::query()->where('user_id', $request->user()->id)->first();
            if ($teacher === null || ! $this->teacherCanManageContext($teacher, (int) $data['classroom_id'], (int) $data['subject_id'], $newTerm)) {
                abort(403, 'Vous ne pouvez modifier une évaluation que pour vos propres affectations.');
            }

            $data['teacher_id'] = $teacher->id;
        }

        $evaluation->update($data);

        return EvaluationResource::make($evaluation->fresh()->load(['classroom.level', 'subject', 'period']));
    }

    public function destroy(Evaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluationAccess($evaluation);
        $this->assertRoleCanManageEvaluationType($evaluation);

        $term = $evaluation->term()->first();
        if ($term) {
            SchoolYearContext::assertTermNotArchived($term);
        }
        $period = $evaluation->period()->first();
        if ($period !== null) {
            $this->assertPeriodIsWritable($period);
        }

        $evaluation->delete();

        return response()->json(null, 204);
    }

    public function publish(Evaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluationAccess($evaluation);

        $term = $evaluation->term()->first();
        if ($term) {
            SchoolYearContext::assertTermNotArchived($term);
        }
        $period = $evaluation->period()->first();
        if ($period !== null) {
            $this->assertPeriodIsWritable($period);
        }

        $evaluation->update(['published_at' => now()]);

        return response()->json(['message' => 'Évaluation publiée.'], 200);
    }

    public function unpublish(Request $request, Evaluation $evaluation): JsonResponse
    {
        if (!$request->user()?->hasRole('admin')) {
            abort(403, 'Seul un administrateur peut dépublier une évaluation.');
        }

        $this->authorizeEvaluationAccess($evaluation);

        $term = $evaluation->term()->first();
        if ($term) {
            SchoolYearContext::assertTermNotArchived($term);
        }
        $period = $evaluation->period()->first();
        if ($period !== null) {
            $this->assertPeriodIsWritable($period);
        }

        $evaluation->update(['published_at' => null]);

        return response()->json(['message' => 'Évaluation dépubliée.'], 200);
    }

    // ─── Notes liées à l'évaluation ─────────────────────────────────────────

    /**
     * Liste les élèves de la classe + leur note (s'il y en a une) pour cette évaluation.
     */
    public function grades(Evaluation $evaluation): AnonymousResourceCollection
    {
        $this->authorizeEvaluationAccess($evaluation);

        $studentsQuery = Student::query()
            ->where('classroom_id', $evaluation->classroom_id);
        SchoolYearContext::applyStudentEnrollmentYearId(
            $studentsQuery,
            $evaluation->term()->value('school_year_id'),
        );
        $students = $studentsQuery
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $existing = Grade::query()
            ->where('evaluation_id', $evaluation->id)
            ->get()
            ->keyBy('student_id');

        // Construit une "liste virtuelle" : pour chaque élève, on retourne soit son grade existant, soit un placeholder.
        $rows = $students->map(function (Student $s) use ($existing, $evaluation): Grade {
            return $existing->get($s->id) ?? new Grade([
                'evaluation_id' => $evaluation->id,
                'student_id' => $s->id,
                'value' => null,
                'absent' => false,
            ]);
        });

        // Charge la relation student manuellement
        $rows->each(fn (Grade $g) => $g->setRelation('student', $students->firstWhere('id', $g->student_id)));

        return GradeResource::collection($rows);
    }

    /**
     * Saisie / mise à jour en lot des notes.
     * Journalise toute modification dans grade_audits (CDC §UC-02).
     */
    public function saveGrades(GradeBatchRequest $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluationAccess($evaluation);

        $term = $evaluation->term()->first();
        if ($term) {
            SchoolYearContext::assertTermNotArchived($term);
        }
        $period = $evaluation->period()->first();
        if ($period !== null) {
            $this->assertPeriodIsWritable($period);
        }

        $userId = $request->user()?->id;
        $payload = $request->validated()['grades'];

        DB::transaction(function () use ($evaluation, $payload, $userId): void {
            foreach ($payload as $row) {
                $studentId = (int) $row['student_id'];
                $value = isset($row['value']) ? (float) $row['value'] : null;
                $absent = (bool) ($row['absent'] ?? false);

                $grade = Grade::query()->firstOrNew([
                    'evaluation_id' => $evaluation->id,
                    'student_id' => $studentId,
                ]);

                $wasNew = ! $grade->exists;
                $oldValue = $wasNew ? null : ($grade->value !== null ? (float) $grade->value : null);
                $oldAbsent = $wasNew ? null : (bool) $grade->absent;

                if ($absent) {
                    $value = null;
                }

                $grade->value = $value;
                $grade->absent = $absent;

                if (! $grade->exists) {
                    $grade->created_by = $userId;
                }
                $grade->updated_by = $userId;
                $grade->save();

                $changed = $wasNew
                    ? ($value !== null || $absent)
                    : ($this->gradeValueChanged($oldValue, $value) || $oldAbsent !== $absent);
                if ($changed) {
                    GradeAudit::query()->create([
                        'grade_id' => $grade->id,
                        'old_value' => $oldValue,
                        'new_value' => $value,
                        'old_absent' => $oldAbsent,
                        'new_absent' => $absent,
                        'user_id' => $userId,
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Notes enregistrées.'], 200);
    }

    private function applyEvaluationAccessScope(Builder $query, Request $request): void
    {
        $user = $request->user();

        if ($user?->hasRole('admin')) {
            if (! AdminScopeContext::isGlobalAdmin($user)) {
                $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                    ->whereIn('cycle', AdminScopeContext::allowedCycles($user)));
            }
            return;
        }

        if (! $user?->hasRole('enseignant')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $assignments = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->get(['classroom_id', 'subject_id', 'school_year_id', 'term_id', 'is_main']);

        $query->where(function (Builder $evaluationQuery) use ($teacher, $assignments): void {
            $evaluationQuery->where('teacher_id', $teacher->id);

            if ($assignments->isEmpty()) {
                return;
            }

            $evaluationQuery->orWhere(function (Builder $assignmentScope) use ($assignments): void {
                foreach ($assignments as $assignment) {
                    $assignmentScope->orWhere(function (Builder $assignmentQuery) use ($assignment): void {
                        $assignmentQuery
                            ->where('classroom_id', $assignment->classroom_id)
                            ->whereHas('term', function (Builder $termQuery) use ($assignment): void {
                                $termQuery->where('school_year_id', $assignment->school_year_id);

                                if ($assignment->term_id !== null) {
                                    $termQuery->whereKey($assignment->term_id);
                                }
                            });

                        if ($assignment->subject_id !== null) {
                            $assignmentQuery->where('subject_id', $assignment->subject_id);

                            return;
                        }

                        if ($assignment->is_main) {
                            $assignmentQuery->whereIn('subject_id', function ($subQuery) use ($assignment): void {
                                $subQuery->select('subject_id')
                                    ->from('classroom_subject')
                                    ->where('classroom_id', $assignment->classroom_id);
                            });
                        }
                    });
                }
            });
        });
    }

    private function authorizeEvaluationAccess(Evaluation $evaluation): void
    {
        $user = request()->user();

        if ($user?->hasRole('admin')) {
            AdminScopeContext::assertClassroomAllowed($user, $evaluation->classroom_id);
            return;
        }

        if ($user?->hasRole('enseignant')) {
            $teacher = Teacher::query()->where('user_id', $user->id)->first();
            if ($teacher) {
                if ((int) $evaluation->teacher_id === (int) $teacher->id) {
                    return;
                }

                if ($this->teacherCanManageContext($teacher, $evaluation->classroom_id, $evaluation->subject_id, $evaluation->term)) {
                    return;
                }
            }
        }

        abort(403, 'Accès non autorisé à cette évaluation.');
    }

    private function teacherCanManageContext(Teacher $teacher, int $classroomId, int $subjectId, ?Term $term): bool
    {
        $assignmentQuery = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->where('classroom_id', $classroomId);

        if ($term !== null) {
            $assignmentQuery
                ->where('school_year_id', $term->school_year_id)
                ->where(function (Builder $termScope) use ($term): void {
                    $termScope
                        ->whereNull('term_id')
                        ->orWhere('term_id', $term->id);
                });
        }

        if ((clone $assignmentQuery)->where('subject_id', $subjectId)->exists()) {
            return true;
        }

        if ($teacher->teacher_type !== Teacher::TYPE_PRIMAIRE) {
            return false;
        }

        $isTitular = (clone $assignmentQuery)
            ->where('is_main', true)
            ->whereNull('subject_id')
            ->exists();

        if (! $isTitular) {
            return false;
        }

        return \App\Models\ClassRoom::query()
            ->whereKey($classroomId)
            ->whereHas('subjects', fn (Builder $subjectQuery) => $subjectQuery->whereKey($subjectId))
            ->exists();
    }

    private function gradeValueChanged(?float $oldValue, ?float $newValue): bool
    {
        if ($oldValue === null || $newValue === null) {
            return $oldValue !== $newValue;
        }

        return abs($oldValue - $newValue) > 0.00001;
    }

    private function assertPeriodIsWritable(Period $period): void
    {
        if ($period->isClosed()) {
            throw ValidationException::withMessages([
                'period_id' => 'La période sélectionnée est clôturée.',
            ]);
        }
    }

    private function assertRoleCanManageEvaluationType(Evaluation $evaluation): void
    {
        $user = request()->user();

        if (! Evaluation::roleCanManageType($user?->role, $evaluation->type)) {
            abort(403, $evaluation->isExam()
                ? 'Seuls les administrateurs peuvent gérer les examens de période.'
                : 'Les administrateurs ne gèrent que les examens de période.');
        }
    }
}
