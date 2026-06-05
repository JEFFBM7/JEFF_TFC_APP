<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\SchoolYearContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Détecte les seuils d'alerte d'absentéisme (CDC §4.5).
 *
 * Les seuils sont paramétrables via `app_settings` :
 * - attendance.consecutive_threshold (défaut 3)
 * - attendance.rolling_threshold     (défaut 5)
 * - attendance.rolling_window_days   (défaut 30)
 * - attendance.late_threshold        (défaut 5)
 * - attendance.late_window_days      (défaut 30)
 */
class AttendanceAlertService
{
    public const REASON_CONSECUTIVE = 'consecutive_3';

    public const REASON_ROLLING = 'rolling_30';

    public const REASON_LATE = 'late_threshold';

    /**
     * @return array{
     *   triggered: bool,
     *   reasons: array<int, string>,
     *   count_recent_30d: int,
     *   consecutive: int,
     *   late_count: int,
     *   thresholds: array<string, int>
     * }
     */
    public function check(Student $student, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $now = CarbonImmutable::now();

        $consecutiveThreshold = (int) AppSetting::get('attendance.consecutive_threshold', 3);
        $rollingThreshold = (int) AppSetting::get('attendance.rolling_threshold', 5);
        $rollingWindow = (int) AppSetting::get('attendance.rolling_window_days', 30);
        $lateThreshold = (int) AppSetting::get('attendance.late_threshold', 5);
        $lateWindow = (int) AppSetting::get('attendance.late_window_days', 30);

        $absencesQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_ABSENT)
            ->where('justified', false)
            ->orderBy('date');

        $latesQuery = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', Attendance::STATUS_LATE)
            ->orderBy('date');

        $currentYear = SchoolYearContext::current();
        if ($currentYear !== null) {
            SchoolYearContext::applyYearDateRange($absencesQuery, $currentYear);
            SchoolYearContext::applyYearDateRange($latesQuery, $currentYear);
        }

        $unjustifiedAbsences = $absencesQuery->get();
        $lates = $latesQuery->get();

        $hasOpenStudentJustification = $unjustifiedAbsences->contains(
            fn (Attendance $a) => $a->countsForAbsenteeismAlert($now)
        );

        $consecutive = $this->countConsecutive($unjustifiedAbsences);
        $countRolling = $this->countWithinDays($unjustifiedAbsences, $today, $rollingWindow);
        $countLate = $this->countWithinDays($lates, $today, $lateWindow);

        $reasons = array_values(array_filter([
            $consecutive >= $consecutiveThreshold ? self::REASON_CONSECUTIVE : null,
            $countRolling >= $rollingThreshold ? self::REASON_ROLLING : null,
            $countLate >= $lateThreshold ? self::REASON_LATE : null,
        ]));

        return [
            'triggered' => count($reasons) > 0 && $hasOpenStudentJustification,
            'reasons' => $reasons,
            'count_recent_30d' => $countRolling,
            'consecutive' => $consecutive,
            'late_count' => $countLate,
            'thresholds' => [
                'consecutive' => $consecutiveThreshold,
                'rolling' => $rollingThreshold,
                'rolling_window_days' => $rollingWindow,
                'late' => $lateThreshold,
                'late_window_days' => $lateWindow,
            ],
        ];
    }

    /** @param Collection<int, Attendance> $absences */
    public function countConsecutive(Collection $absences): int
    {
        $dates = $absences->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->sort()->values();
        if ($dates->isEmpty()) {
            return 0;
        }

        $maxRun = $run = 1;
        $prev = null;
        foreach ($dates as $d) {
            if ($prev === null) {
                $prev = CarbonImmutable::parse($d);

                continue;
            }
            $cur = CarbonImmutable::parse($d);
            $diff = $prev->diffInDays($cur);
            if ($diff <= 1.0) { // jours consécutifs
                $run++;
                $maxRun = max($maxRun, $run);
            } else {
                $run = 1;
            }
            $prev = $cur;
        }

        return $maxRun;
    }

    /** @param Collection<int, Attendance> $records */
    public function countWithinDays(Collection $records, CarbonImmutable $today, int $days): int
    {
        $threshold = $today->subDays($days);

        return $records->filter(fn (Attendance $a) => $a->date->greaterThanOrEqualTo($threshold))->count();
    }
}
