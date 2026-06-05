<?php

namespace App\Services;

use App\Http\Requests\Api\V1\TeacherRequest;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherAssignmentSyncService
{
    /** @param  array<string, mixed>  $data */
    public function sync(Teacher $teacher, array $data, int $schoolYearId, ?\App\Models\User $actor = null): void
    {
        SchoolYearContext::assertNotArchivedById($schoolYearId);

        match ($data['teacher_type']) {
            Teacher::TYPE_PRIMAIRE => $this->syncPrimary(
                $teacher,
                (int) $data['classroom_id'],
                $schoolYearId,
                $actor,
            ),
            Teacher::TYPE_SECONDAIRE => ($data['secondary_role'] ?? TeacherRequest::SECONDARY_ROLE_SPECIALIST) === TeacherRequest::SECONDARY_ROLE_PRINCIPAL
                ? $this->syncSecondaryPrincipal(
                    $teacher,
                    (int) $data['classroom_id'],
                    array_map('intval', $data['subject_ids'] ?? []),
                    $schoolYearId,
                    $actor,
                )
                : $this->syncSecondary(
                    $teacher,
                    (int) $data['subject_id'],
                    array_map('intval', $data['classroom_ids'] ?? []),
                    $schoolYearId,
                    $actor,
                ),
            default => throw ValidationException::withMessages([
                'teacher_type' => ['Type d\'enseignant invalide.'],
            ]),
        };
    }

    private function syncPrimary(
        Teacher $teacher,
        int $classroomId,
        int $schoolYearId,
        ?\App\Models\User $actor,
    ): void {
        $classroom = ClassRoom::query()->with(['subjects', 'level'])->findOrFail($classroomId);
        $this->assertPrimaryOrMaternelClassroom($classroom);
        if ($actor) {
            AdminScopeContext::assertClassroomAllowed($actor, $classroomId);
        }

        $this->syncTitularWithAllSubjects($teacher, $classroom, $classroomId, $schoolYearId);
    }

    /** @param  list<int>  $subjectIds */
    private function syncSecondaryPrincipal(
        Teacher $teacher,
        int $classroomId,
        array $subjectIds,
        int $schoolYearId,
        ?\App\Models\User $actor,
    ): void {
        $classroom = ClassRoom::query()->with(['subjects', 'level'])->findOrFail($classroomId);
        $this->assertSecondaryClassroom($classroom);
        if ($actor) {
            AdminScopeContext::assertClassroomAllowed($actor, $classroomId);
        }

        $this->assertSubjectsBelongToClassroom($classroom, $subjectIds);
        $this->syncTitularWithSelectedSubjects($teacher, $classroomId, $subjectIds, $schoolYearId);
    }

    private function syncTitularWithAllSubjects(
        Teacher $teacher,
        ClassRoom $classroom,
        int $classroomId,
        int $schoolYearId,
    ): void {
        $subjectIds = $classroom->subjects->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->syncTitularWithSelectedSubjects($teacher, $classroomId, $subjectIds, $schoolYearId);
    }

    /**
     * Complète les affectations matière par matière pour le titulaire primaire/maternel
     * (ex. programme généré après l'affectation de la classe).
     */
    public function refreshClassroomTitularSubjects(int $classroomId, int $schoolYearId): bool
    {
        $classroom = ClassRoom::query()->with(['subjects', 'level'])->find($classroomId);
        if ($classroom === null || ! in_array($classroom->level?->cycle, [Level::CYCLE_MATERNEL, Level::CYCLE_PRIMAIRE], true)) {
            return false;
        }

        $mainAssignment = TeacherAssignment::query()
            ->where('classroom_id', $classroomId)
            ->where('school_year_id', $schoolYearId)
            ->where('is_main', true)
            ->whereNull('subject_id')
            ->first();

        if ($mainAssignment === null) {
            return false;
        }

        $teacher = Teacher::query()->find($mainAssignment->teacher_id);
        if ($teacher === null || $teacher->teacher_type !== Teacher::TYPE_PRIMAIRE) {
            return false;
        }

        $subjectIds = $classroom->subjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($subjectIds === []) {
            return false;
        }

        $existingSubjectIds = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->where('classroom_id', $classroomId)
            ->where('school_year_id', $schoolYearId)
            ->whereNotNull('subject_id')
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($subjectIds, $existingSubjectIds) === [] && count($existingSubjectIds) === count($subjectIds)) {
            return false;
        }

        DB::transaction(function () use ($teacher, $classroomId, $subjectIds, $schoolYearId): void {
            TeacherAssignment::query()
                ->where('teacher_id', $teacher->id)
                ->where('classroom_id', $classroomId)
                ->where('school_year_id', $schoolYearId)
                ->whereNotNull('subject_id')
                ->delete();

            foreach (array_values(array_unique($subjectIds)) as $subjectId) {
                TeacherAssignment::query()->create([
                    'teacher_id' => $teacher->id,
                    'classroom_id' => $classroomId,
                    'subject_id' => $subjectId,
                    'school_year_id' => $schoolYearId,
                    'is_main' => false,
                ]);
            }
        });

        return true;
    }

    /** @param  list<int>  $subjectIds */
    private function syncTitularWithSelectedSubjects(
        Teacher $teacher,
        int $classroomId,
        array $subjectIds,
        int $schoolYearId,
    ): void {
        DB::transaction(function () use ($teacher, $classroomId, $subjectIds, $schoolYearId): void {
            TeacherAssignment::query()
                ->where('classroom_id', $classroomId)
                ->where('school_year_id', $schoolYearId)
                ->where('teacher_id', '<>', $teacher->id)
                ->delete();

            TeacherAssignment::query()
                ->where('teacher_id', $teacher->id)
                ->where('school_year_id', $schoolYearId)
                ->delete();

            $mainAssignment = TeacherAssignment::query()->create([
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroomId,
                'subject_id' => null,
                'school_year_id' => $schoolYearId,
                'is_main' => true,
            ]);

            $this->ensureSingleMainAssignment($mainAssignment);

            foreach (array_values(array_unique($subjectIds)) as $subjectId) {
                TeacherAssignment::query()->create([
                    'teacher_id' => $teacher->id,
                    'classroom_id' => $classroomId,
                    'subject_id' => $subjectId,
                    'school_year_id' => $schoolYearId,
                    'is_main' => false,
                ]);
            }
        });
    }

    /** @param  list<int>  $subjectIds */
    private function assertSubjectsBelongToClassroom(ClassRoom $classroom, array $subjectIds): void
    {
        if ($subjectIds === []) {
            return;
        }

        $allowedIds = $classroom->subjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invalid = array_diff($subjectIds, $allowedIds);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'subject_ids' => ['Un ou plusieurs cours ne sont pas rattachés à la classe sélectionnée.'],
            ]);
        }
    }

    private function assertPrimaryOrMaternelClassroom(ClassRoom $classroom): void
    {
        $cycle = $classroom->level?->cycle;

        if (! in_array($cycle, [Level::CYCLE_PRIMAIRE, Level::CYCLE_MATERNEL], true)) {
            throw ValidationException::withMessages([
                'classroom_id' => ['La classe titulaire doit appartenir au cycle maternel ou primaire.'],
            ]);
        }
    }

    /** @param  list<int>  $classroomIds */
    private function syncSecondary(
        Teacher $teacher,
        int $subjectId,
        array $classroomIds,
        int $schoolYearId,
        ?\App\Models\User $actor,
    ): void {
        if ($classroomIds === []) {
            throw ValidationException::withMessages([
                'classroom_ids' => ['Sélectionnez au moins une classe.'],
            ]);
        }

        $subject = Subject::query()->findOrFail($subjectId);
        $classrooms = ClassRoom::query()->with('level')->whereIn('id', $classroomIds)->get();

        if ($classrooms->count() !== count(array_unique($classroomIds))) {
            throw ValidationException::withMessages([
                'classroom_ids' => ['Une ou plusieurs classes sont invalides.'],
            ]);
        }

        foreach ($classrooms as $classroom) {
            $this->assertSecondaryClassroom($classroom);
            if ($actor) {
                AdminScopeContext::assertClassroomAllowed($actor, $classroom->id);
            }
        }

        DB::transaction(function () use ($teacher, $subject, $classroomIds, $schoolYearId): void {
            TeacherAssignment::query()
                ->where('teacher_id', $teacher->id)
                ->where('school_year_id', $schoolYearId)
                ->delete();

            foreach ($classroomIds as $classroomId) {
                TeacherAssignment::query()->create([
                    'teacher_id' => $teacher->id,
                    'classroom_id' => $classroomId,
                    'subject_id' => $subject->id,
                    'school_year_id' => $schoolYearId,
                    'is_main' => false,
                ]);
            }
        });
    }

    private function assertSecondaryClassroom(ClassRoom $classroom): void
    {
        $cycle = $classroom->level?->cycle;
        if (! in_array($cycle, [Level::CYCLE_SECONDAIRE, Level::CYCLE_CTEB], true)) {
            throw ValidationException::withMessages([
                'classroom_id' => ['Les classes du cycle secondaire ou CTEB sont attendues.'],
            ]);
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
}
