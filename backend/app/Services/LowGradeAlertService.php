<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Term;
use App\Models\Student;
use App\Support\SchoolYearContext;
use Illuminate\Support\Collection;

/**
 * Détection des élèves en difficulté académique.
 *
 * Seuil paramétrable : `grades.low_average_threshold` (défaut 8/20).
 * Un élève est "en difficulté" pour un trimestre donné si sa moyenne générale
 * (calculée par ReportCardService) est strictement inférieure au seuil.
 */
class LowGradeAlertService
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    /** Seuil courant. */
    public function threshold(): float
    {
        return (float) AppSetting::get('grades.low_average_threshold', 8.0);
    }

    /**
     * Retourne la moyenne si elle est sous le seuil, null sinon.
     */
    public function check(Student $student, Term $term): ?float
    {
        $report = $this->reportCards->compute($student, $term);
        $avg = $report['overall_average'] ?? null;

        if ($avg === null) {
            return null;
        }

        return $avg < $this->threshold() ? (float) $avg : null;
    }

    /**
     * Liste des élèves d'un trimestre dont la moyenne est sous le seuil.
     *
     * @return Collection<int, array{student: Student, average: float, threshold: float}>
     */
    public function studentsAtRisk(Term $term, ?int $classroomId = null): Collection
    {
        $threshold = $this->threshold();
        $query = Student::query()->with(['classroom.level', 'parents.user']);
        SchoolYearContext::applyStudentEnrollmentYearId($query, $term->school_year_id);
        if ($classroomId !== null) {
            $query->where('classroom_id', $classroomId);
        }

        return $query->get()
            ->map(function (Student $student) use ($term, $threshold) {
                $avg = $this->reportCards->compute($student, $term)['overall_average'] ?? null;
                if ($avg === null || $avg >= $threshold) {
                    return null;
                }

                return [
                    'student' => $student,
                    'average' => (float) $avg,
                    'threshold' => $threshold,
                ];
            })
            ->filter()
            ->values();
    }
}
