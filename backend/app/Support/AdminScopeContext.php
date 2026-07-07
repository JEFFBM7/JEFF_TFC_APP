<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminScopeContext
{
    public const GLOBAL = 'global';

    public const PRIMARY_MATERNAL = 'primary_maternal';

    public const SECONDARY_TECHNICAL = 'secondary_technical';

    public const SCOPES = [
        self::GLOBAL,
        self::PRIMARY_MATERNAL,
        self::SECONDARY_TECHNICAL,
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::GLOBAL => 'Administrateur général',
            self::PRIMARY_MATERNAL => 'Admin cycle Primaire & Maternel',
            self::SECONDARY_TECHNICAL => 'Admin cycle Secondaire & Technique',
        ];
    }

    /** @return array<int, string> */
    public static function cyclesForScope(?string $scope): array
    {
        return match ($scope) {
            self::PRIMARY_MATERNAL => [Level::CYCLE_MATERNEL, Level::CYCLE_PRIMAIRE],
            self::SECONDARY_TECHNICAL => [Level::CYCLE_CTEB, Level::CYCLE_SECONDAIRE],
            default => Level::CYCLES,
        };
    }

    /** @return array<int, string> */
    public static function allowedCycles(?User $user): array
    {
        if (! $user || $user->role !== UserRole::Admin) {
            return Level::CYCLES;
        }

        return self::cyclesForScope($user->admin_scope ?: self::GLOBAL);
    }

    public static function isGlobalAdmin(?User $user): bool
    {
        return $user?->role === UserRole::Admin
            && ($user->admin_scope === null || $user->admin_scope === self::GLOBAL);
    }

    public static function assertGlobalAdmin(?User $user): void
    {
        if (! self::isGlobalAdmin($user)) {
            abort(403, 'Réservé à l’administrateur général.');
        }
    }

    public static function scopeLabel(?User $user): ?string
    {
        if ($user?->role !== UserRole::Admin) {
            return null;
        }

        return self::labels()[$user->admin_scope ?: self::GLOBAL] ?? null;
    }

    /** @return array<string, mixed> */
    public static function userPayload(User $user): array
    {
        return [
            'admin_scope' => $user->role === UserRole::Admin ? ($user->admin_scope ?: self::GLOBAL) : null,
            'admin_scope_label' => self::scopeLabel($user),
            'admin_cycles' => $user->role === UserRole::Admin ? self::allowedCycles($user) : [],
            'term_applicable_cycles' => self::allowedTermApplicableCycles($user),
            'teacher_cycles' => $user->role === UserRole::Enseignant ? self::teacherLevelCycles($user) : [],
            'teacher_id' => $user->role === UserRole::Enseignant
                ? Teacher::query()->where('user_id', $user->id)->value('id')
                : null,
        ];
    }

    /**
     * null = accès aux deux calendriers (trimestres + semestres).
     *
     * @return list<string>|null
     */
    public static function allowedTermApplicableCycles(?User $user): ?array
    {
        if ($user === null) {
            return [];
        }

        if ($user->role === UserRole::Admin) {
            if (self::isGlobalAdmin($user)) {
                return null;
            }

            return self::levelCyclesToApplicableTermCycles(self::allowedCycles($user));
        }

        if ($user->role === UserRole::Enseignant) {
            $cycles = self::teacherLevelCycles($user);
            if ($cycles === []) {
                return [];
            }

            return self::levelCyclesToApplicableTermCycles($cycles);
        }

        return null;
    }

    /** @return list<string> */
    public static function levelCyclesToApplicableTermCycles(array $levelCycles): array
    {
        return array_values(array_unique(array_map(
            fn (string $cycle) => Term::applicableCycleForLevelCycle($cycle),
            $levelCycles,
        )));
    }

    /** @return list<string> */
    public static function teacherLevelCycles(User $user): array
    {
        $teacherId = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($teacherId === null) {
            return [];
        }

        return TeacherAssignment::query()
            ->where('teacher_id', $teacherId)
            ->with('classroom.level:id,cycle')
            ->get()
            ->map(fn (TeacherAssignment $assignment) => $assignment->classroom?->level?->cycle)
            ->filter(fn (?string $cycle) => is_string($cycle) && $cycle !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @param Builder<Term> $query */
    public static function applyTermScope(Builder $query, Request|User|null $actor): Builder
    {
        $user = $actor instanceof Request ? $actor->user() : $actor;
        $allowed = self::allowedTermApplicableCycles($user);

        if ($allowed === null) {
            return $query;
        }

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('applicable_cycle', $allowed);
    }

    /** @param Collection<int, Term> $terms */
    public static function filterTermsForUser(Collection $terms, ?User $user): Collection
    {
        $allowed = self::allowedTermApplicableCycles($user);

        if ($allowed === null) {
            return $terms->values();
        }

        return $terms
            ->filter(fn (Term $term) => in_array($term->applicable_cycle, $allowed, true))
            ->values();
    }

    public static function assertTermApplicableCycleAllowed(?User $user, Term $term): void
    {
        $allowed = self::allowedTermApplicableCycles($user);

        if ($allowed === null) {
            return;
        }

        if (! in_array($term->applicable_cycle, $allowed, true)) {
            abort(403, 'Ce calendrier scolaire est hors de votre périmètre.');
        }
    }

    /** @param Builder<ClassRoom>|Relation<ClassRoom, *, *> $query */
    public static function applyClassroomScope(Builder|Relation $query, Request|User|null $actor): Builder|Relation
    {
        $user = $actor instanceof Request ? $actor->user() : $actor;
        if ($user?->role !== UserRole::Admin || self::isGlobalAdmin($user)) {
            return $query;
        }

        $cycles = self::allowedCycles($user);
        if ($cycles === []) {
            return $query->whereRaw('1 = 0');
        }

        return self::applyClassroomCycleScope($query, $cycles);
    }

    /**
     * Limite les classes visibles aux affectations de l'enseignant (année courante si précisée).
     *
     * @param Builder<ClassRoom>|Relation<ClassRoom, *, *> $query
     */
    public static function applyTeacherClassroomScope(
        Builder|Relation $query,
        ?User $user,
        ?int $schoolYearId = null,
    ): Builder|Relation {
        if ($user?->role !== UserRole::Enseignant) {
            return $query;
        }

        $teacherId = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($teacherId === null) {
            return $query->whereRaw('1 = 0');
        }

        $assignmentQuery = TeacherAssignment::query()->where('teacher_id', $teacherId);
        if ($schoolYearId !== null) {
            $assignmentQuery->where('school_year_id', $schoolYearId);
        }

        $classroomIds = $assignmentQuery->distinct()->pluck('classroom_id');

        if ($classroomIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query instanceof Relation ? $query->getRelated() : $query->getModel();

        return $query->whereIn($model->qualifyColumn('id'), $classroomIds);
    }

    /** @param Builder<Level>|Relation<Level, *, *> $query */
    public static function applyLevelScope(Builder|Relation $query, Request|User|null $actor): Builder|Relation
    {
        $user = $actor instanceof Request ? $actor->user() : $actor;
        if ($user?->role !== UserRole::Admin || self::isGlobalAdmin($user)) {
            return $query;
        }

        return $query->whereIn('cycle', self::allowedCycles($user));
    }

    /**
     * Cycle effectif d'une classe (niveau direct, sinon niveau de la classe scolaire).
     *
     * @param Builder<ClassRoom>|Relation<ClassRoom, *, *> $query
     */
    public static function applyClassroomCycleScope(Builder|Relation $query, array $cycles): Builder|Relation
    {
        return $query->where(function (Builder $classroomQuery) use ($cycles): void {
            $classroomQuery
                ->whereHas('level', fn (Builder $levelQuery) => $levelQuery->whereIn('cycle', $cycles))
                ->orWhere(function (Builder $fallback) use ($cycles): void {
                    $fallback->whereNull('level_id')
                        ->whereHas('schoolClass.level', fn (Builder $levelQuery) => $levelQuery
                            ->whereIn('cycle', $cycles));
                });
        });
    }

    /** @param Builder<Student>|Relation<Student, *, *> $query */
    public static function applyStudentScope(Builder|Relation $query, Request|User|null $actor): Builder|Relation
    {
        $user = $actor instanceof Request ? $actor->user() : $actor;
        if ($user?->role !== UserRole::Admin || self::isGlobalAdmin($user)) {
            return $query;
        }

        $cycles = self::allowedCycles($user);
        if ($cycles === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'classroom',
            fn (Builder $classroomQuery) => self::applyClassroomCycleScope($classroomQuery, $cycles),
        );
    }

    public static function requestedCycleIsAllowed(Request $request): bool
    {
        if (! $request->filled('cycle')) {
            return true;
        }

        return in_array($request->string('cycle')->value(), self::allowedCycles($request->user()), true);
    }

    public static function assertCycleAllowed(?User $user, ?string $cycle): void
    {
        if ($cycle !== null && ! in_array($cycle, self::allowedCycles($user), true)) {
            abort(403, 'Cette donnée est hors de votre périmètre administratif.');
        }
    }

    public static function assertLevelAllowed(?User $user, int $levelId): void
    {
        $cycle = Level::query()->whereKey($levelId)->value('cycle');
        self::assertCycleAllowed($user, is_string($cycle) ? $cycle : null);
    }

    public static function assertClassroomAllowed(?User $user, int|ClassRoom|null $classroom): void
    {
        if ($classroom === null) {
            return;
        }

        $model = $classroom instanceof ClassRoom
            ? $classroom->loadMissing('level')
            : ClassRoom::query()->with('level:id,cycle')->find($classroom);

        if (! $model) {
            return;
        }

        self::assertCycleAllowed($user, $model->level?->cycle);
    }

    public static function assertStudentAllowed(?User $user, Student $student): void
    {
        $student->loadMissing('classroom.level');
        self::assertCycleAllowed($user, $student->classroom?->level?->cycle);
    }

    /** @return Collection<int, int> */
    public static function allowedClassroomIds(?User $user): Collection
    {
        return self::applyClassroomScope(ClassRoom::query(), $user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public static function expectedTeacherTypeForScope(?string $scope): ?string
    {
        return match ($scope) {
            self::PRIMARY_MATERNAL => Teacher::TYPE_PRIMAIRE,
            self::SECONDARY_TECHNICAL => Teacher::TYPE_SECONDAIRE,
            default => null,
        };
    }

    /** @return list<string> */
    public static function allowedTeacherTypes(?User $user): array
    {
        $expected = self::expectedTeacherTypeForScope($user?->admin_scope);

        if ($user?->role === UserRole::Admin && $expected !== null) {
            return [$expected];
        }

        return Teacher::TYPES;
    }

    /** @param Builder<Teacher> $query */
    public static function applyTeacherScope(Builder $query, Request|User|null $actor): Builder
    {
        $user = $actor instanceof Request ? $actor->user() : $actor;

        if ($user?->role !== UserRole::Admin || self::isGlobalAdmin($user)) {
            return $query;
        }

        $expectedType = self::expectedTeacherTypeForScope($user->admin_scope);

        if ($expectedType === null) {
            return $query;
        }

        $allowedCycles = self::allowedCycles($user);

        return $query->where('teacher_type', $expectedType)
            ->where(function (Builder $visibility) use ($allowedCycles): void {
                $visibility->whereDoesntHave('assignments')
                    ->orWhereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->whereHas('classroom.level', fn (Builder $levelQuery) => $levelQuery
                            ->whereIn('cycle', $allowedCycles)));
            });
    }

    public static function assertTeacherAllowed(?User $user, Teacher $teacher): void
    {
        if ($user?->role !== UserRole::Admin || self::isGlobalAdmin($user)) {
            return;
        }

        $expectedType = self::expectedTeacherTypeForScope($user->admin_scope);

        if ($expectedType !== null && $teacher->teacher_type !== $expectedType) {
            abort(403, 'Cet enseignant est hors de votre périmètre administratif.');
        }

        $hasOutsideScope = $teacher->assignments()
            ->whereHas('classroom.level', fn (Builder $levelQuery) => $levelQuery
                ->whereNotIn('cycle', self::allowedCycles($user)))
            ->exists();

        if ($hasOutsideScope) {
            abort(403, 'Cet enseignant est lié à des données hors de votre périmètre.');
        }
    }
}
