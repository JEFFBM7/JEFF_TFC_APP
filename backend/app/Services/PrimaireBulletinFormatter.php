<?php

namespace App\Services;

use App\Models\Level;
use App\Models\Term;

class PrimaireBulletinFormatter
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    public function isPrimaireDebutStudent(\App\Models\Student $student): bool
    {
        $student->loadMissing('classroom.level');
        $level = $student->classroom?->level;
        if ($level?->cycle !== Level::CYCLE_PRIMAIRE) {
            return false;
        }

        $abbreviation = strtoupper((string) ($level->abbreviation ?? ''));

        return in_array($abbreviation, ['1P', '2P'], true);
    }

    public function resolveGradeYear(\App\Models\Student $student): int
    {
        $student->loadMissing('classroom.level');
        $abbreviation = strtoupper((string) ($student->classroom?->level?->abbreviation ?? ''));
        if ($abbreviation === '2P' || str_starts_with($abbreviation, '2')) {
            return 2;
        }

        return 1;
    }

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
        Term $anchorTerm,
    ): array {
        $student->loadMissing(['classroom.level']);
        $anchorTerm->loadMissing('schoolYear');

        $trimesters = $anchorTerm->schoolYear
            ->terms()
            ->where('applicable_cycle', 'primaire')
            ->orderBy('position')
            ->take(3)
            ->get();

        $reports = $trimesters
            ->map(fn ($term) => $this->reportCards->compute($student, $term))
            ->values()
            ->all();

        $gradeYear = $this->resolveGradeYear($student);
        $yearLabel = $gradeYear === 1 ? '1ère' : '2ème';

        $definitions = config('primary_bulletin_rows.rows', []);
        $rows = [];
        $grandTotal = 0.0;
        $maxGrand = 0;

        foreach ($definitions as $definition) {
            $kind = $definition['kind'];
            if ($kind !== 'subject') {
                $rows[] = $this->emptyRow($definition);

                continue;
            }

            $tTotals = [];
            $rowGrand = 0.0;
            $hasScore = false;

            foreach ($reports as $index => $report) {
                $subject = $this->findSubject($report['subjects'] ?? collect(), $definition);
                $trimesterMax = (int) $definition['trimester_max'];
                $total = $this->trimesterTotal($subject, $trimesterMax);
                $tTotals[$index + 1] = $total;
                if ($total !== null) {
                    $hasScore = true;
                    $rowGrand += $total;
                }
            }

            if ($hasScore) {
                $grandTotal += $rowGrand;
            }

            $maxGrand += ((int) $definition['trimester_max']) * 3;

            $rows[] = [
                'kind' => $kind,
                'label' => $definition['label'],
                't1_max' => $definition['trimester_max'],
                't1_p1' => '',
                't1_p2' => '',
                't1_exam_max' => $definition['exam_max'],
                't1_total' => $this->formatPoints($tTotals[1] ?? null),
                't2_max' => $definition['trimester_max'],
                't2_p1' => '',
                't2_p2' => '',
                't2_exam_max' => $definition['exam_max'],
                't2_total' => $this->formatPoints($tTotals[2] ?? null),
                't3_max' => $definition['trimester_max'],
                't3_p1' => '',
                't3_p2' => '',
                't3_exam_max' => $definition['exam_max'],
                't3_total' => $this->formatPoints($tTotals[3] ?? null),
                'grand_total' => $this->formatPoints($hasScore ? $rowGrand : null),
            ];
        }

        $percentage = $maxGrand > 0
            ? number_format(($grandTotal / $maxGrand) * 100, 2, ',', ' ').' %'
            : '—';

        return [
            'bulletin_title' => "BULLETIN — DEGRÉ ÉLÉMENTAIRE / ÉDUCATION SPÉCIALE ({$yearLabel} année)",
            'school_year_name' => (string) ($anchorTerm->schoolYear?->name ?? '—'),
            'rows' => $rows,
            'percentage' => $percentage,
            'appreciation' => $reports[2]['appreciation'] ?? $reports[1]['appreciation'] ?? $reports[0]['appreciation'] ?? null,
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $subjects */
    private function findSubject(\Illuminate\Support\Collection $subjects, array $definition): ?array
    {
        $legacy = config('primary_bulletin_rows.legacy_aliases', []);
        $targets = [$definition['label']];

        if (! empty($definition['alias_key']) && isset($legacy[$definition['alias_key']])) {
            $targets = array_merge($targets, $legacy[$definition['alias_key']]);
        }

        $targets = array_merge($targets, $definition['aliases'] ?? []);

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
    private function trimesterTotal(?array $subjectRow, int $trimesterMax): ?float
    {
        if ($subjectRow === null || $subjectRow['average'] === null || $trimesterMax <= 0) {
            return null;
        }

        return round(((float) $subjectRow['average'] / 20) * $trimesterMax, 2);
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
        $isSubtotal = $definition['kind'] === 'subtotal';

        return [
            'kind' => $definition['kind'],
            'label' => $definition['label'],
            't1_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't1_p1' => '',
            't1_p2' => '',
            't1_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't1_total' => '',
            't2_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't2_p1' => '',
            't2_p2' => '',
            't2_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't2_total' => '',
            't3_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't3_p1' => '',
            't3_p2' => '',
            't3_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't3_total' => '',
            'grand_total' => '',
        ];
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtolower(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
