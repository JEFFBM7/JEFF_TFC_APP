<?php

namespace App\Services;

use App\Models\Level;
use App\Models\Term;

class PrimaireBulletinFormatter
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    public function isOfficialPrimaireStudent(\App\Models\Student $student): bool
    {
        return $this->resolveTier($student) !== null;
    }

    /** @deprecated Préférer isOfficialPrimaireStudent() */
    public function isPrimaireDebutStudent(\App\Models\Student $student): bool
    {
        return $this->resolveTier($student) === 'debut';
    }

    public function isPrimaireMoyenStudent(\App\Models\Student $student): bool
    {
        return $this->resolveTier($student) === 'moyen';
    }

    public function isPrimaireTerminalStudent(\App\Models\Student $student): bool
    {
        return $this->resolveTier($student) === 'terminal';
    }

    public function resolveTier(\App\Models\Student $student): ?string
    {
        $student->loadMissing('classroom.level');
        $level = $student->classroom?->level;
        if ($level?->cycle !== Level::CYCLE_PRIMAIRE) {
            return null;
        }

        $abbreviation = strtoupper((string) ($level->abbreviation ?? ''));

        if (in_array($abbreviation, ['1P', '2P'], true)) {
            return 'debut';
        }

        if (in_array($abbreviation, ['3P', '4P'], true)) {
            return 'moyen';
        }

        if (in_array($abbreviation, ['5P', '6P'], true)) {
            return 'terminal';
        }

        return null;
    }

    public function resolveGradeYear(\App\Models\Student $student): int
    {
        $student->loadMissing('classroom.level');
        $abbreviation = strtoupper((string) ($student->classroom?->level?->abbreviation ?? ''));

        return match (true) {
            $abbreviation === '2P' || str_starts_with($abbreviation, '2') => 2,
            $abbreviation === '4P' || str_starts_with($abbreviation, '4') => 4,
            $abbreviation === '6P' || str_starts_with($abbreviation, '6') => 6,
            $abbreviation === '5P' || str_starts_with($abbreviation, '5') => 5,
            $abbreviation === '3P' || str_starts_with($abbreviation, '3') => 3,
            default => 1,
        };
    }

    /**
     * @return array{
     *   bulletin_title: string,
     *   school_year_name: string,
     *   rows: array<int, array<string, mixed>>,
     *   percentage: string,
     *   appreciation: ?string,
     *   form_code: string,
     *   grade_year: int
     * }
     */
    public function buildAnnualPresentation(
        \App\Models\Student $student,
        Term $anchorTerm,
    ): array {
        $tier = $this->resolveTier($student) ?? 'debut';
        $configKey = match ($tier) {
            'terminal' => 'primary_terminal_bulletin_rows',
            'moyen' => 'primary_moyen_bulletin_rows',
            default => 'primary_bulletin_rows',
        };

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
        $bulletinTitle = $this->bulletinTitle($tier, $gradeYear);

        $definitions = config("{$configKey}.rows", []);
        $legacy = config("{$configKey}.legacy_aliases", []);
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
                $subject = $this->findSubject($report['subjects'] ?? collect(), $definition, $legacy);
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

            $trimesterMax = (int) $definition['trimester_max'];
            $periodMax = (int) $definition['period_max'];
            $examMax = (int) $definition['exam_max'];

            $rows[] = [
                'kind' => $kind,
                'label' => $definition['label'],
                't1_max_per' => $periodMax,
                't1_p1' => '',
                't1_p2' => '',
                't1_exam_max' => $examMax,
                't1_exam' => '',
                't1_trim_max' => $trimesterMax,
                't1_total' => $this->formatPoints($tTotals[1] ?? null),
                't2_p1' => '',
                't2_p2' => '',
                't2_exam_max' => $examMax,
                't2_exam' => '',
                't2_trim_max' => $trimesterMax,
                't2_total' => $this->formatPoints($tTotals[2] ?? null),
                't3_p1' => '',
                't3_p2' => '',
                't3_exam_max' => $examMax,
                't3_exam' => '',
                't3_trim_max' => $trimesterMax,
                't3_total' => $this->formatPoints($tTotals[3] ?? null),
                'annual_max' => $trimesterMax * 3,
                'grand_total' => $this->formatPoints($hasScore ? $rowGrand : null),
            ];
        }

        $percentage = $maxGrand > 0
            ? number_format(($grandTotal / $maxGrand) * 100, 2, ',', ' ').' %'
            : '—';

        return [
            'bulletin_title' => $bulletinTitle,
            'school_year_name' => (string) ($anchorTerm->schoolYear?->name ?? '—'),
            'rows' => $rows,
            'percentage' => $percentage,
            'appreciation' => $reports[2]['appreciation'] ?? $reports[1]['appreciation'] ?? $reports[0]['appreciation'] ?? null,
            'form_code' => (string) config("{$configKey}.form_code", 'IGE/P.S./001'),
            'grade_year' => $gradeYear,
        ];
    }

    private function bulletinTitle(string $tier, int $gradeYear): string
    {
        if ($tier === 'terminal') {
            return "BULLETIN DE L'ÉLÈVE DEGRÉ TERMINAL ({$gradeYear}e ANNÉE)";
        }

        if ($tier === 'moyen') {
            $yearLabel = $gradeYear === 4 ? '4ème' : '3ème';

            return "BULLETIN DE L'ÉLÈVE : DEGRÉ MOYEN ({$yearLabel} année)";
        }

        $yearLabel = $gradeYear === 2 ? '2ème' : '1ère';

        return "BULLETIN — DEGRÉ ÉLÉMENTAIRE / ÉDUCATION SPÉCIALE ({$yearLabel} année)";
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $subjects
     * @param  array<string, array<int, string>>  $legacy
     */
    private function findSubject(\Illuminate\Support\Collection $subjects, array $definition, array $legacy): ?array
    {
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
            't1_max_per' => $isSubtotal ? $definition['period_max'] : '',
            't1_p1' => '',
            't1_p2' => '',
            't1_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't1_exam' => '',
            't1_trim_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't1_total' => '',
            't2_p1' => '',
            't2_p2' => '',
            't2_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't2_exam' => '',
            't2_trim_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't2_total' => '',
            't3_p1' => '',
            't3_p2' => '',
            't3_exam_max' => $isSubtotal ? $definition['exam_max'] : '',
            't3_exam' => '',
            't3_trim_max' => $isSubtotal ? $definition['trimester_max'] : '',
            't3_total' => '',
            'annual_max' => $isSubtotal ? ((int) $definition['trimester_max']) * 3 : '',
            'grand_total' => '',
        ];
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtolower(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
