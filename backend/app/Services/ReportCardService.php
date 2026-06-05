<?php

namespace App\Services;

use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Period;
use App\Models\ReportCardAppreciation;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\Student;
use App\Models\Subject;
use App\Support\SchoolYearContext;
use Illuminate\Support\Collection;

class ReportCardService
{
    /**
     * Calcule le bulletin d'un élève pour un trimestre donné.
     *
     * Renvoie un tableau de la forme :
     * [
     *   'student' => Student,
     *   'term' => Term,
     *   'subjects' => Collection<array{subject:Subject, average:?float, coefficient:float, count:int}>,
     *   'period_averages' => Collection<array{period:Period, average:?float}>,
     *   'overall_average' => ?float,
     *   'total_coefficient' => float,
     * ]
     *
     * @return array<string, mixed>
     */
    public function compute(
        Student $student,
        Term $term,
        bool $publishedOnly = false,
        ?Period $scopePeriod = null,
    ): array {
        $student->loadMissing('classroom');
        $classroomSubjects = $this->subjectsForReport($student, $term, $publishedOnly);

        $rows = collect();
        $weightedSum = 0.0;
        $totalCoef = 0.0;
        $periods = $term->periods()->orderBy('position')->get();
        if ($scopePeriod !== null) {
            $periods = $periods->where('id', $scopePeriod->id)->values();
        }

        foreach ($classroomSubjects as $subject) {
            /** @var Subject $subject */
            $coef = (float) ($subject->pivot?->coefficient ?? $subject->default_coefficient ?? 1);

            if ($scopePeriod !== null) {
                $average = $this->computeSubjectPeriodAverage($student, $subject, $scopePeriod, $publishedOnly);
                $count = $this->gradesForSubjectPeriod($student, $subject, $scopePeriod, $publishedOnly)->count();
                $evaluations = $this->gradeDetailsForSubjectTerm($student, $subject, $term, $publishedOnly, $scopePeriod);
            } elseif ($periods->isNotEmpty()) {
                $subjectPeriodAverages = $periods
                    ->map(fn (Period $period) => $this->computeSubjectPeriodAverage($student, $subject, $period, $publishedOnly))
                    ->filter(fn (?float $average) => $average !== null)
                    ->values();

                $average = $subjectPeriodAverages->isEmpty()
                    ? null
                    : round((float) $subjectPeriodAverages->avg(), 2);

                $count = Grade::query()
                    ->where('student_id', $student->id)
                    ->whereHas('evaluation', fn ($q) => $q
                        ->where('subject_id', $subject->id)
                        ->where('term_id', $term->id)
                        ->whereIn('period_id', $periods->pluck('id'))
                        ->when($publishedOnly, fn ($evaluationQuery) => $evaluationQuery->whereNotNull('published_at')))
                    ->where('absent', false)
                    ->whereNotNull('value')
                    ->count();
                $evaluations = $this->gradeDetailsForSubjectTerm($student, $subject, $term, $publishedOnly);
            } else {
                $grades = $this->gradesForSubjectTerm($student, $subject, $term, $publishedOnly);

                $average = $this->computeComponentWeightedAverage($grades);
                $count = $grades->count();
                $evaluations = $this->gradeDetailsForSubjectTerm($student, $subject, $term, $publishedOnly);
            }

            $rows->push([
                'subject' => $subject,
                'average' => $average,
                'coefficient' => $coef,
                'count' => $count,
                'evaluations' => $evaluations,
            ]);

            if ($average !== null) {
                $weightedSum += $average * $coef;
                $totalCoef += $coef;
            }
        }

        $periodAverages = $periods
            ->map(fn (Period $period) => [
                'period' => $period,
                'average' => $this->computePeriodAverage($student, $period, $publishedOnly),
            ])
            ->values();

        if ($scopePeriod !== null) {
            $overall = $this->computePeriodAverage($student, $scopePeriod, $publishedOnly);
        } else {
            $availablePeriodAverages = $periodAverages
                ->pluck('average')
                ->filter(fn (?float $average) => $average !== null)
                ->values();

            $overall = $availablePeriodAverages->isNotEmpty()
                ? round((float) $availablePeriodAverages->avg(), 2)
                : ($totalCoef > 0 ? round($weightedSum / $totalCoef, 2) : null);
        }

        $appreciation = ReportCardAppreciation::query()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->first();

        return [
            'student' => $student,
            'term' => $term,
            'subjects' => $rows,
            'period_averages' => $periodAverages,
            'overall_average' => $overall,
            'total_coefficient' => $totalCoef,
            'appreciation' => $appreciation?->content,
            'scoped_period_id' => $scopePeriod?->id,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function classRanking(int $classroomId, Term $term, bool $publishedOnly = false): Collection
    {
        $studentsQuery = Student::query()->where('classroom_id', $classroomId);
        SchoolYearContext::applyStudentEnrollmentYearId($studentsQuery, $term->school_year_id);
        $students = $studentsQuery->get();

        return $students
            ->map(fn (Student $s) => [
                'student' => $s,
                'overall_average' => $this->compute($s, $term, $publishedOnly)['overall_average'],
            ])
            ->sortByDesc(fn ($r) => $r['overall_average'] ?? -1)
            ->values();
    }

    public function computePeriodAverage(Student $student, Period $period, bool $publishedOnly = false): ?float
    {
        $weightedSum = 0.0;
        $totalCoef = 0.0;

        foreach ($this->subjectsForReport($student, $period->term, $publishedOnly) as $subject) {
            /** @var Subject $subject */
            $coef = (float) ($subject->pivot?->coefficient ?? $subject->default_coefficient ?? 1);
            $average = $this->computeSubjectPeriodAverage($student, $subject, $period, $publishedOnly);

            if ($average === null) {
                continue;
            }

            $weightedSum += $average * $coef;
            $totalCoef += $coef;
        }

        return $totalCoef > 0 ? round($weightedSum / $totalCoef, 2) : null;
    }

    private function subjectsForReport(Student $student, Term $term, bool $publishedOnly = false): Collection
    {
        $student->loadMissing('classroom');

        $evaluationSubjectIds = Evaluation::query()
            ->where('term_id', $term->id)
            ->when($student->classroom_id, fn ($query) => $query->where('classroom_id', $student->classroom_id))
            ->when($publishedOnly, fn ($query) => $query->whereNotNull('published_at'))
            ->distinct()
            ->pluck('subject_id')
            ->filter()
            ->values();

        $gradedSubjectIds = Grade::query()
            ->where('student_id', $student->id)
            ->whereHas('evaluation', fn ($q) => $q
                ->where('term_id', $term->id)
                ->when($student->classroom_id, fn ($evaluationQuery) => $evaluationQuery->where('classroom_id', $student->classroom_id))
                ->when($publishedOnly, fn ($evaluationQuery) => $evaluationQuery->whereNotNull('published_at')))
            ->with('evaluation:id,subject_id')
            ->get()
            ->pluck('evaluation.subject_id')
            ->filter()
            ->unique()
            ->values();

        $subjectIds = $evaluationSubjectIds
            ->merge($gradedSubjectIds)
            ->unique()
            ->values();

        if ($subjectIds->isEmpty()) {
            return collect();
        }

        $classroomSubjectsById = $student->classroom
            ? $student->classroom->subjects()->get()->keyBy('id')
            : collect();

        return Subject::query()
            ->whereIn('id', $subjectIds)
            ->orderBy('name')
            ->get()
            ->map(function (Subject $subject) use ($classroomSubjectsById) {
                $linked = $classroomSubjectsById->get($subject->id);
                if ($linked?->pivot) {
                    $subject->setRelation('pivot', $linked->pivot);
                }

                return $subject;
            })
            ->values();
    }

    public function computeAnnualAverage(Student $student, SchoolYear $schoolYear, bool $publishedOnly = false): ?float
    {
        $termAverages = $schoolYear->terms()
            ->orderBy('position')
            ->get()
            ->map(fn (Term $term) => $this->compute($student, $term, $publishedOnly)['overall_average'] ?? null)
            ->filter(fn (?float $average) => $average !== null)
            ->values();

        return $termAverages->isNotEmpty()
            ? round((float) $termAverages->avg(), 2)
            : null;
    }

    private function computeSubjectPeriodAverage(
        Student $student,
        Subject $subject,
        Period $period,
        bool $publishedOnly = false,
    ): ?float {
        $grades = $this->gradesForSubjectPeriod($student, $subject, $period, $publishedOnly);

        return $this->computeComponentWeightedAverage($grades);
    }

    /**
     * @return Collection<int, Grade>
     */
    private function gradesForSubjectTerm(
        Student $student,
        Subject $subject,
        Term $term,
        bool $publishedOnly = false,
    ): Collection {
        return Grade::query()
            ->where('student_id', $student->id)
            ->whereHas('evaluation', fn ($q) => $q
                ->where('subject_id', $subject->id)
                ->where('term_id', $term->id)
                ->when($publishedOnly, fn ($evaluationQuery) => $evaluationQuery->whereNotNull('published_at')))
            ->where('absent', false)
            ->whereNotNull('value')
            ->with('evaluation:id,type,max_value')
            ->get();
    }

    /**
     * @return Collection<int, Grade>
     */
    private function gradesForSubjectPeriod(
        Student $student,
        Subject $subject,
        Period $period,
        bool $publishedOnly = false,
    ): Collection {
        return Grade::query()
            ->where('student_id', $student->id)
            ->whereHas('evaluation', fn ($q) => $q
                ->where('subject_id', $subject->id)
                ->where('period_id', $period->id)
                ->when($publishedOnly, fn ($evaluationQuery) => $evaluationQuery->whereNotNull('published_at')))
            ->where('absent', false)
            ->whereNotNull('value')
            ->with('evaluation:id,type,max_value')
            ->get();
    }

    private function gradeDetailsForSubjectTerm(
        Student $student,
        Subject $subject,
        Term $term,
        bool $publishedOnly = false,
        ?Period $scopePeriod = null,
    ): Collection {
        return Grade::query()
            ->where('student_id', $student->id)
            ->whereHas('evaluation', fn ($q) => $q
                ->where('subject_id', $subject->id)
                ->where('term_id', $term->id)
                ->when($scopePeriod !== null, fn ($evaluationQuery) => $evaluationQuery->where('period_id', $scopePeriod->id))
                ->when($publishedOnly, fn ($evaluationQuery) => $evaluationQuery->whereNotNull('published_at')))
            ->with('evaluation:id,name,type,held_on,max_value,period_id')
            ->get()
            ->sortBy([
                fn (Grade $grade) => $grade->evaluation?->held_on?->toDateString() ?? '',
                fn (Grade $grade) => $grade->evaluation?->id ?? 0,
            ])
            ->values()
            ->map(fn (Grade $grade) => [
                'id' => $grade->id,
                'evaluation_id' => $grade->evaluation_id,
                'name' => $grade->evaluation?->name,
                'type' => $grade->evaluation?->type,
                'type_label' => Evaluation::typeLabel($grade->evaluation?->type),
                'component' => Evaluation::componentForType($grade->evaluation?->type),
                'period_id' => $grade->evaluation?->period_id,
                'held_on' => $grade->evaluation?->held_on?->toDateString(),
                'value' => $grade->value === null ? null : (float) $grade->value,
                'max_value' => $grade->evaluation?->max_value === null ? 20.0 : (float) $grade->evaluation->max_value,
                'normalized_value' => $this->normalizedGradeValue($grade),
                'absent' => (bool) $grade->absent,
            ]);
    }

    /**
     * Calcule la note de période RDC sur /20 :
     * interros/devoirs = 40%, examen de période = 60%.
     * Si une composante manque encore, la moyenne disponible est utilisée telle quelle.
     *
     * @param  Collection<int, Grade>  $grades
     */
    private function computeComponentWeightedAverage(Collection $grades): ?float
    {
        $continuousAverage = $this->componentAverage($grades, Evaluation::COMPONENT_CONTINUOUS);
        $examAverage = $this->componentAverage($grades, Evaluation::COMPONENT_EXAM);

        if ($continuousAverage !== null && $examAverage !== null) {
            return round(
                ($continuousAverage * Evaluation::CONTINUOUS_WEIGHT)
                + ($examAverage * Evaluation::EXAM_WEIGHT),
                2,
            );
        }

        return match (true) {
            $examAverage !== null => round($examAverage, 2),
            $continuousAverage !== null => round($continuousAverage, 2),
            default => null,
        };
    }

    /**
     * @param  Collection<int, Grade>  $grades
     */
    private function componentAverage(Collection $grades, string $component): ?float
    {
        $values = $grades
            ->filter(fn (Grade $grade) => Evaluation::componentForType($grade->evaluation?->type) === $component)
            ->map(fn (Grade $grade) => $this->normalizedGradeValue($grade))
            ->filter(fn (?float $value) => $value !== null)
            ->values();

        return $values->isEmpty() ? null : (float) $values->avg();
    }

    private function normalizedGradeValue(Grade $grade): ?float
    {
        if ($grade->value === null) {
            return null;
        }

        $maxValue = (float) ($grade->evaluation?->max_value ?? 20);
        if ($maxValue <= 0) {
            return null;
        }

        return ((float) $grade->value / $maxValue) * 20;
    }
}
