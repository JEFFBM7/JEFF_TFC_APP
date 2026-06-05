<?php

namespace App\Services;

use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\Period;
use App\Models\Student;

class StudentTimelineService
{
    public function __construct(
        private readonly AttendanceStatsService $attendanceStats,
        private readonly ReportCardService $reportCards,
    ) {}

    /**
     * @return array{
     *   term_averages: array<int, array{term_id:int,school_year_id:int,label:string,average:?float}>,
     *   period_averages: array<int, array{period_id:int,term_id:int,school_year_id:int,label:string,average:?float}>,
     *   annual_averages: array<int, array{school_year_id:int,label:string,average:?float}>,
     *   monthly_attendance: array<int, array{value:string,label:string,absences:int,lates:int}>
     * }
     */
    public function forStudent(Student $student, ?SchoolYear $schoolYear = null): array
    {
        $terms = Term::query()
            ->with('schoolYear')
            ->orderBy('starts_on')
            ->orderBy('position')
            ->get();

        $termAverages = $terms
            ->map(function (Term $term) use ($student) {
                $average = $this->reportCards->compute($student, $term)['overall_average'] ?? null;
                $yearName = $term->schoolYear?->name;

                return [
                    'term_id' => $term->id,
                    'school_year_id' => $term->school_year_id,
                    'label' => trim($term->name.($yearName ? ' - '.$yearName : '')),
                    'average' => $average === null ? null : (float) $average,
                ];
            })
            ->values()
            ->all();

        $periods = Period::query()
            ->with('term.schoolYear')
            ->orderBy('starts_on')
            ->orderBy('position')
            ->get();

        $periodAverages = $periods
            ->map(function (Period $period) use ($student) {
                $average = $this->reportCards->computePeriodAverage($student, $period);
                $term = $period->term;
                $yearName = $term?->schoolYear?->name;
                $termName = $term?->name;

                return [
                    'period_id' => $period->id,
                    'term_id' => $period->term_id,
                    'school_year_id' => $term?->school_year_id,
                    'label' => trim($period->name.($termName ? ' - '.$termName : '').($yearName ? ' - '.$yearName : '')),
                    'average' => $average === null ? null : (float) $average,
                ];
            })
            ->values()
            ->all();

        $yearsQuery = SchoolYear::query()->orderBy('starts_on');
        if ($schoolYear !== null) {
            $yearsQuery->whereKey($schoolYear->id);
        }

        $annualAverages = $yearsQuery
            ->get()
            ->map(function (SchoolYear $year) use ($student) {
                $average = $this->reportCards->computeAnnualAverage($student, $year);

                return [
                    'school_year_id' => $year->id,
                    'label' => $year->name,
                    'average' => $average === null ? null : (float) $average,
                ];
            })
            ->values()
            ->all();

        $monthlyAttendance = $schoolYear !== null
            ? $this->attendanceStats->monthlyAttendance($schoolYear, $student->id)
            : [];

        return [
            'term_averages' => $termAverages,
            'period_averages' => $periodAverages,
            'annual_averages' => $annualAverages,
            'monthly_attendance' => $monthlyAttendance,
        ];
    }
}
