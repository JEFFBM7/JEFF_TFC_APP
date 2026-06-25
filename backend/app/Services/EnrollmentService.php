<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Pont entre les inscriptions (source de vérité de l'historique) et le cache
 * porté par students (classroom_id + enrollment_school_year_id).
 */
class EnrollmentService
{
    /**
     * Aligne le cache des élèves sur les inscriptions de l'année passée en
     * paramètre. Appelé lorsqu'une année devient l'année courante : chaque
     * élève inscrit dans cette année voit son pointeur (classe + année)
     * recalé. On utilise une mise à jour de masse (sans événement Eloquent)
     * pour éviter toute récursion avec {@see Student::syncCurrentEnrollment()}.
     */
    public function syncStudentPointersForYear(SchoolYear $year): void
    {
        Enrollment::query()
            ->where('school_year_id', $year->id)
            ->orderBy('id')
            ->chunkById(200, function (Collection $enrollments) use ($year): void {
                foreach ($enrollments as $enrollment) {
                    Student::query()->whereKey($enrollment->student_id)->update([
                        'classroom_id' => $enrollment->classroom_id,
                        'enrollment_school_year_id' => $year->id,
                    ]);
                }
            });
    }

    /**
     * IDs des élèves inscrits dans une classe pour une année donnée.
     *
     * @return Collection<int, int>
     */
    public function studentIdsForClassroomYear(int $classroomId, int $schoolYearId): Collection
    {
        return Enrollment::query()
            ->forYear($schoolYearId)
            ->forClassroom($classroomId)
            ->pluck('student_id');
    }

    /**
     * IDs des élèves inscrits pour une année (toutes classes confondues).
     *
     * @return Collection<int, int>
     */
    public function studentIdsForYear(int $schoolYearId): Collection
    {
        return Enrollment::query()->forYear($schoolYearId)->pluck('student_id');
    }

    /**
     * IDs des classes ayant au moins un élève inscrit pour l'année.
     *
     * @return Collection<int, int>
     */
    public function classroomIdsForYear(int $schoolYearId): Collection
    {
        return Enrollment::query()
            ->forYear($schoolYearId)
            ->whereNotNull('classroom_id')
            ->distinct()
            ->pluck('classroom_id');
    }
}
