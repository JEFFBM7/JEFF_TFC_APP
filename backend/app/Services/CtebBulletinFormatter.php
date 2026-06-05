<?php

namespace App\Services;

use App\Models\Level;

class CtebBulletinFormatter
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    /**
     * @return array{
     *   bulletin_title: string,
     *   school_year_name: string,
     *   rows: array<int, array<string, mixed>>,
     *   percentage: string,
     *   appreciation: ?string
     * }
     */
    public function buildAnnualPresentation(
        \App\Models\Student $student,
        \App\Models\Term $anchorTerm,
    ): array {
        $student->loadMissing(['classroom.level']);
        $anchorTerm->loadMissing('schoolYear');

        $semesters = $anchorTerm->schoolYear
            ->terms()
            ->where('applicable_cycle', 'secondaire')
            ->orderBy('position')
            ->take(2)
            ->get();

        $reports = $semesters
            ->map(fn ($term) => $this->reportCards->compute($student, $term))
            ->values()
            ->all();

        $gradeYear = $this->resolveGradeYear($student);

        $rows = [];
        $grandTotal = 0.0;
        $maxGrand = 0;

        $definitions = config("cteb_bulletin.rows_by_grade.{$gradeYear}")
            ?? config('cteb_bulletin.rows', []);

        foreach ($definitions as $definition) {
            $kind = $definition['kind'];
            if ($kind !== 'subject') {
                $rows[] = $this->emptyRow($definition);

                continue;
            }

            $subjectS1 = $this->findSubject($reports[0]['subjects'] ?? collect(), $definition);
            $subjectS2 = $this->findSubject($reports[1]['subjects'] ?? collect(), $definition);

            $s1Total = $this->semesterTotal($subjectS1, (int) $definition['semester_max']);
            $s2Total = $this->semesterTotal($subjectS2, (int) $definition['semester_max']);
            $rowGrand = ($s1Total ?? 0) + ($s2Total ?? 0);

            if ($s1Total !== null || $s2Total !== null) {
                $grandTotal += $rowGrand;
            }

            $maxGrand += ((int) $definition['semester_max']) * 2;

            $rows[] = [
                'kind' => $kind,
                'label' => $definition['label'],
                's1_max' => $definition['semester_max'],
                's1_p1' => '',
                's1_p2' => '',
                's1_exam_max' => $definition['exam_max'],
                's1_total' => $this->formatPoints($s1Total),
                's2_max' => $definition['semester_max'],
                's2_p1' => '',
                's2_p2' => '',
                's2_exam_max' => $definition['exam_max'],
                's2_total' => $this->formatPoints($s2Total),
                'grand_total' => $this->formatPoints($s1Total === null && $s2Total === null ? null : $rowGrand),
            ];
        }

        $percentage = $maxGrand > 0
            ? number_format(($grandTotal / $maxGrand) * 100, 2, ',', ' ').' %'
            : '—';

        return [
            'bulletin_title' => "BULLETIN DE LA {$gradeYear}ème ANNÉE CYCLE TERMINAL DE L'ÉDUCATION DE BASE (CTEB)",
            'school_year_name' => (string) ($anchorTerm->schoolYear?->name ?? '—'),
            'rows' => $rows,
            'percentage' => $percentage,
            'appreciation' => $reports[1]['appreciation'] ?? $reports[0]['appreciation'] ?? null,
        ];
    }

    public function isCtebStudent(\App\Models\Student $student): bool
    {
        $student->loadMissing('classroom.level');

        return $student->classroom?->level?->cycle === Level::CYCLE_CTEB;
    }

    public function resolveGradeYear(\App\Models\Student $student): int
    {
        $student->loadMissing('classroom.level');
        $abbreviation = strtoupper((string) ($student->classroom?->level?->abbreviation ?? ''));
        if ($abbreviation === '8EB' || str_starts_with($abbreviation, '8')) {
            return 8;
        }

        $levelName = strtolower((string) ($student->classroom?->level?->name ?? ''));

        return str_contains($levelName, '8') ? 8 : 7;
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $subjects */
    private function findSubject(\Illuminate\Support\Collection $subjects, array $definition): ?array
    {
        $targets = array_merge(
            [$definition['label']],
            $definition['aliases'] ?? [],
        );

        return $subjects->first(function (array $row) use ($targets) {
            $name = $this->normalize((string) ($row['subject']->name ?? ''));

            foreach ($targets as $target) {
                $needle = $this->normalize((string) $target);
                if ($needle !== '' && (str_contains($name, $needle) || str_contains($needle, $name))) {
                    return true;
                }
            }

            return false;
        });
    }

  /** @param  array<string, mixed>|null  $subjectRow */
    private function semesterTotal(?array $subjectRow, int $semesterMax): ?float
    {
        if ($subjectRow === null || $subjectRow['average'] === null || $semesterMax <= 0) {
            return null;
        }

        return round(((float) $subjectRow['average'] / 20) * $semesterMax, 2);
    }

    private function formatPoints(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }

    /** @param  array<string, mixed>  $definition */
    private function emptyRow(array $definition): array
    {
        return [
            'kind' => $definition['kind'],
            'label' => $definition['label'],
            's1_max' => $definition['kind'] === 'subtotal' ? $definition['semester_max'] : '',
            's1_p1' => '',
            's1_p2' => '',
            's1_exam_max' => $definition['kind'] === 'subtotal' ? $definition['exam_max'] : '',
            's1_total' => '',
            's2_max' => $definition['kind'] === 'subtotal' ? $definition['semester_max'] : '',
            's2_p1' => '',
            's2_p2' => '',
            's2_exam_max' => $definition['kind'] === 'subtotal' ? $definition['exam_max'] : '',
            's2_total' => '',
            'grand_total' => '',
        ];
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtolower(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
