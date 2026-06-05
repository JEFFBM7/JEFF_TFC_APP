<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Term;
use App\Models\Student;
use App\Notifications\LowAverageNotification;
use App\Notifications\ReportCardPublishedNotification;
use App\Support\SchoolYearContext;
use Illuminate\Support\Facades\Notification;

/**
 * Clôture d'un trimestre (CDC §4.9 / UC-04).
 *
 * Marque le trimestre comme clôturé puis envoie le bulletin PDF par e-mail
 * à chaque parent rattaché à un élève. Si activé via `app_settings`, envoie
 * également une alerte aux parents des élèves dont la moyenne est inférieure
 * au seuil de vigilance.
 */
class TermClosureService
{
    public function __construct(private readonly LowGradeAlertService $lowGradeAlerts) {}

    /**
     * @return array{closed: bool, students_notified: int, parents_notified: int, low_average_alerts: int}
     */
    public function close(Term $term): array
    {
        if ($term->isClosed()) {
            return [
                'closed' => true,
                'students_notified' => 0,
                'parents_notified' => 0,
                'low_average_alerts' => 0,
            ];
        }

        $term->update(['closed_at' => now()]);

        $studentQuery = Student::query()->with('parents.user');
        SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $term->school_year_id);
        $students = $studentQuery->get();

        $studentsNotified = 0;
        $parentsNotified = 0;
        $lowAverageAlerts = 0;

        $notifyLowAverage = (bool) AppSetting::get('grades.notify_parents_on_low_average', true);
        $threshold = $this->lowGradeAlerts->threshold();

        foreach ($students as $student) {
            $parentUsers = $student->parents
                ->map(fn ($p) => $p->user)
                ->filter()
                ->filter(fn ($u) => filled($u->email));

            if ($parentUsers->isEmpty()) {
                continue;
            }

            Notification::send($parentUsers, new ReportCardPublishedNotification($student, $term));
            $studentsNotified++;
            $parentsNotified += $parentUsers->count();

            if ($notifyLowAverage) {
                $average = $this->lowGradeAlerts->check($student, $term);
                if ($average !== null) {
                    Notification::send(
                        $parentUsers,
                        new LowAverageNotification($student, $term, $average, $threshold),
                    );
                    $lowAverageAlerts++;
                }
            }
        }

        return [
            'closed' => true,
            'students_notified' => $studentsNotified,
            'parents_notified' => $parentsNotified,
            'low_average_alerts' => $lowAverageAlerts,
        ];
    }
}
