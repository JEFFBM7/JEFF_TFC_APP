<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\SchoolYear;
use Carbon\Carbon;

class AttendanceStatsService
{
    /**
     * @return array<int, array{value:string,label:string,absences:int,lates:int}>
     */
    public function monthlyAttendance(SchoolYear $schoolYear, ?int $studentId = null): array
    {
        $start = Carbon::parse($schoolYear->starts_on)->startOfMonth();
        $end = Carbon::parse($schoolYear->ends_on)->startOfMonth();

        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            $monthStart = $cursor->copy()->startOfMonth()->max(Carbon::parse($schoolYear->starts_on));
            $monthEnd = $cursor->copy()->endOfMonth()->min(Carbon::parse($schoolYear->ends_on));

            $query = Attendance::query()
                ->whereDate('date', '>=', $monthStart)
                ->whereDate('date', '<=', $monthEnd)
                ->when($studentId !== null, fn ($q) => $q->where('student_id', $studentId));

            $months[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $this->monthLabel($cursor),
                'absences' => (clone $query)->where('status', Attendance::STATUS_ABSENT)->count(),
                'lates' => (clone $query)->where('status', Attendance::STATUS_LATE)->count(),
            ];
        }

        return $months;
    }

    public function monthLabel(Carbon $month): string
    {
        $labels = [
            1 => 'Janvier',
            2 => 'Février',
            3 => 'Mars',
            4 => 'Avril',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juillet',
            8 => 'Août',
            9 => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Décembre',
        ];

        return $labels[(int) $month->format('n')].' '.$month->format('Y');
    }
}
