<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class MessageRecipientService
{
    /** @return EloquentCollection<int, User> */
    public function contactsFor(User $user): EloquentCollection
    {
        return User::query()
            ->whereIn('id', $this->allowedRecipientIdsFor($user))
            ->select(['id', 'name', 'email', 'role'])
            ->orderBy('name')
            ->get();
    }

    public function canSendTo(User $sender, int $recipientId): bool
    {
        return $this->allowedRecipientIdsFor($sender)->contains($recipientId);
    }

    /** @return Collection<int, int> */
    private function allowedRecipientIdsFor(User $user): Collection
    {
        return match ($user->role) {
            UserRole::Admin,
            UserRole::Secretariat => $this->allRecipientIds($user),
            UserRole::Eleve => $this->studentRecipientIds($user),
            UserRole::Enseignant => $this->teacherRecipientIds($user),
            UserRole::Parent => $this->parentRecipientIds($user),
        };
    }

    /** @return Collection<int, int> */
    private function allRecipientIds(User $user): Collection
    {
        if ($user->role === UserRole::Admin && ! AdminScopeContext::isGlobalAdmin($user)) {
            $classroomIds = AdminScopeContext::allowedClassroomIds($user);
            $studentIds = Student::query()
                ->whereIn('classroom_id', $classroomIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            return $this->administrationRecipientIds()
                ->merge($this->studentUserIdsForClassrooms($classroomIds))
                ->merge($this->parentUserIdsForStudents($studentIds))
                ->merge($this->teacherUserIdsForClassrooms($classroomIds))
                ->filter(fn (int $id) => $id !== $user->id)
                ->unique()
                ->values();
        }

        return User::query()
            ->where('id', '!=', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** @return Collection<int, int> */
    private function administrationRecipientIds(): Collection
    {
        return User::query()
            ->whereIn('role', [
                UserRole::Admin->value,
                UserRole::Secretariat->value,
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /** @return Collection<int, int> */
    private function studentRecipientIds(User $studentUser): Collection
    {
        $adminIds = $this->administrationRecipientIds();

        $student = Student::query()
            ->where('user_id', $studentUser->id)
            ->first();

        if ($student === null || $student->classroom_id === null) {
            return $adminIds
                ->filter(fn (int $id) => $id !== $studentUser->id)
                ->unique()
                ->values();
        }

        return $adminIds
            ->merge($this->teacherUserIdsForClassrooms(collect([$student->classroom_id])))
            ->merge($this->parentUserIdsForStudents(collect([$student->id])))
            ->filter(fn (int $id) => $id !== $studentUser->id)
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    private function teacherRecipientIds(User $teacherUser): Collection
    {
        $adminIds = $this->administrationRecipientIds();

        $teacher = Teacher::query()
            ->where('user_id', $teacherUser->id)
            ->first();

        if ($teacher === null) {
            return $adminIds
                ->filter(fn (int $id) => $id !== $teacherUser->id)
                ->unique()
                ->values();
        }

        $assignments = $this->applyCurrentSchoolYear(
            TeacherAssignment::query()->where('teacher_id', $teacher->id),
        )->get(['classroom_id']);

        $classroomIds = $assignments
            ->pluck('classroom_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($classroomIds->isEmpty()) {
            return $adminIds
                ->filter(fn (int $id) => $id !== $teacherUser->id)
                ->unique()
                ->values();
        }

        $studentIds = Student::query()
            ->whereIn('classroom_id', $classroomIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        return $adminIds
            ->merge($this->studentUserIdsForClassrooms($classroomIds))
            ->merge($this->parentUserIdsForStudents($studentIds))
            ->merge($this->teacherUserIdsForCycles($this->cyclesForClassrooms($classroomIds), $teacherUser))
            ->filter(fn (int $id) => $id !== $teacherUser->id)
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    private function parentRecipientIds(User $parentUser): Collection
    {
        $adminIds = $this->administrationRecipientIds();

        $profile = ParentProfile::query()
            ->where('user_id', $parentUser->id)
            ->first();

        if ($profile === null) {
            return $adminIds->values();
        }

        $classroomIds = $profile->students()
            ->whereNotNull('classroom_id')
            ->pluck('students.classroom_id')
            ->filter()
            ->unique()
            ->values();

        $childrenUserIds = $profile->students()
            ->whereNotNull('students.user_id')
            ->pluck('students.user_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        if ($classroomIds->isEmpty()) {
            return $adminIds
                ->merge($childrenUserIds)
                ->filter(fn (int $id) => $id !== $parentUser->id)
                ->unique()
                ->values();
        }

        return $adminIds
            ->merge($childrenUserIds)
            ->merge($this->teacherUserIdsForClassrooms($classroomIds))
            ->filter(fn (int $id) => $id !== $parentUser->id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $classroomIds
     * @return Collection<int, int>
     */
    private function studentUserIdsForClassrooms(Collection $classroomIds): Collection
    {
        if ($classroomIds->isEmpty()) {
            return collect();
        }

        return Student::query()
            ->whereIn('classroom_id', $classroomIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @return Collection<int, int>
     */
    private function parentUserIdsForStudents(Collection $studentIds): Collection
    {
        if ($studentIds->isEmpty()) {
            return collect();
        }

        return ParentProfile::query()
            ->whereHas('students', fn (Builder $query) => $query->whereIn('students.id', $studentIds))
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * @param  Collection<int, int>  $classroomIds
     * @return Collection<int, int>
     */
    private function teacherUserIdsForClassrooms(Collection $classroomIds): Collection
    {
        if ($classroomIds->isEmpty()) {
            return collect();
        }

        return $this->applyCurrentSchoolYear(
            TeacherAssignment::query()
                ->whereIn('classroom_id', $classroomIds)
                ->whereHas('teacher', fn (Builder $query) => $query->whereNotNull('user_id')),
        )
            ->with('teacher:id,user_id')
            ->get()
            ->pluck('teacher.user_id')
            ->filter()
            ->map(fn ($id) => (int) $id);
    }

    /**
     * @param  Collection<int, int>  $classroomIds
     * @return Collection<int, string>
     */
    private function cyclesForClassrooms(Collection $classroomIds): Collection
    {
        if ($classroomIds->isEmpty()) {
            return collect();
        }

        return ClassRoom::query()
            ->whereIn('id', $classroomIds)
            ->with('level:id,cycle')
            ->get()
            ->pluck('level.cycle')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $cycles
     * @return Collection<int, int>
     */
    private function teacherUserIdsForCycles(Collection $cycles, User $excludedUser): Collection
    {
        if ($cycles->isEmpty()) {
            return collect();
        }

        return $this->applyCurrentSchoolYear(
            TeacherAssignment::query()
                ->whereHas('classroom.level', fn (Builder $query) => $query->whereIn('cycle', $cycles))
                ->whereHas('teacher', function (Builder $query) use ($excludedUser): void {
                    $query
                        ->whereNotNull('user_id')
                        ->where('user_id', '!=', $excludedUser->id);
                }),
        )
            ->with('teacher:id,user_id')
            ->get()
            ->pluck('teacher.user_id')
            ->filter()
            ->map(fn ($id) => (int) $id);
    }

    /** @return Builder<TeacherAssignment> */
    private function applyCurrentSchoolYear(Builder $query): Builder
    {
        $currentSchoolYearId = SchoolYearContext::currentId();
        if ($currentSchoolYearId !== null) {
            $query->where('school_year_id', $currentSchoolYearId);
        }

        return $query;
    }
}
