<?php

namespace App\Support;

use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class SchoolYearContext
{
    /** Code HTTP utilisé lorsqu'une mutation cible une année archivée (lecture seule). */
    public const ARCHIVED_LOCK_STATUS = 423;

    public static function current(): ?SchoolYear
    {
        return SchoolYear::query()->current()->first();
    }

    public static function currentId(): ?int
    {
        $id = SchoolYear::query()->current()->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function requestedOrCurrent(Request $request): ?SchoolYear
    {
        if ($request->filled('school_year_id')) {
            return SchoolYear::query()->find($request->integer('school_year_id'));
        }

        return self::current();
    }

    public static function requestedOrCurrentId(Request $request): ?int
    {
        if ($request->filled('school_year_id')) {
            return $request->integer('school_year_id');
        }

        return self::currentId();
    }

    public static function applySchoolYearColumn(
        Builder $query,
        Request $request,
        string $column = 'school_year_id',
    ): Builder {
        $yearId = self::requestedOrCurrentId($request);
        if ($yearId !== null) {
            $query->where($column, $yearId);
        }

        return $query;
    }

    public static function applyStudentEnrollmentYear(
        Builder $query,
        Request $request,
    ): Builder {
        return self::applyStudentEnrollmentYearId(
            $query,
            self::requestedOrCurrentId($request),
        );
    }

    public static function applyStudentEnrollmentYearId(
        Builder $query,
        ?int $yearId,
    ): Builder {
        if ($yearId === null) {
            return $query;
        }

        // La vérité « qui est inscrit cette année-là » vit dans `enrollments`.
        // `students.enrollment_school_year_id` n'est qu'un cache de l'année
        // courante : s'en servir masquerait les élèves promus/historiques.
        return $query->whereHas('enrollments', fn (Builder $q) => $q->where('school_year_id', $yearId));
    }

    public static function applyEvaluationSchoolYear(
        Builder $query,
        Request $request,
        bool $skipWhenTermIsExplicit = true,
    ): Builder {
        if ($skipWhenTermIsExplicit && $request->filled('term_id') && ! $request->filled('school_year_id')) {
            return $query;
        }

        $yearId = self::requestedOrCurrentId($request);
        if ($yearId !== null) {
            $query->whereHas('term', fn (Builder $termQuery) => $termQuery->where('school_year_id', $yearId));
        }

        return $query;
    }

    public static function applyDateRange(Builder $query, Request $request, string $column = 'date'): Builder
    {
        $year = self::requestedOrCurrent($request);

        if ($request->filled('school_year_id') && $year === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($year !== null) {
            self::applyYearDateRange($query, $year, $column);
        }

        return $query;
    }

    public static function applyYearDateRange(Builder $query, SchoolYear $year, string $column = 'date'): Builder
    {
        return $query
            ->whereDate($column, '>=', $year->starts_on)
            ->whereDate($column, '<=', $year->ends_on);
    }

    public static function applyDateRangeForYearId(Builder $query, ?int $yearId, string $column = 'date'): Builder
    {
        if ($yearId === null) {
            return $query;
        }

        $year = SchoolYear::query()->find($yearId);
        if ($year === null) {
            return $query->whereRaw('1 = 0');
        }

        return self::applyYearDateRange($query, $year, $column);
    }

    /**
     * Lance une réponse 423 (Locked) si l'année cible est archivée.
     * Aucune action si $yearId est null ou si l'année n'est pas archivée.
     */
    public static function assertNotArchivedById(?int $yearId): void
    {
        if ($yearId === null) {
            return;
        }

        $year = SchoolYear::query()->find($yearId);
        if ($year !== null && $year->isArchived()) {
            self::throwArchivedLock();
        }
    }

    /**
     * Vérifie qu'un trimestre n'appartient pas à une année archivée.
     */
    public static function assertTermNotArchived(Term $term): void
    {
        self::assertNotArchivedById($term->school_year_id);
    }

    /**
     * Vérifie qu'une date donnée ne tombe pas dans une année archivée.
     * Utile pour les présences dont le rattachement à une année est implicite.
     */
    public static function assertDateNotInArchivedYear(?string $date): void
    {
        if (! $date) {
            return;
        }

        $year = SchoolYear::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->whereNotNull('archived_at')
            ->first();

        if ($year !== null) {
            self::throwArchivedLock();
        }
    }

    /**
     * @throws HttpResponseException
     */
    private static function throwArchivedLock(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Année scolaire archivée : les données ne peuvent plus être modifiées.',
        ], self::ARCHIVED_LOCK_STATUS));
    }
}
