<?php

namespace App\Services;

use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Génère automatiquement la structure Terms + Periods lors de la création
 * d'une année scolaire, selon le calendrier officiel du Complexe Malunga :
 *
 * Maternelle / Primaire  → 3 trimestres (positions 1–3) + 6 périodes (pos. 1–6)
 * Secondaire / CTEB      → 2 semestres  (positions 4–5) + 4 périodes (pos. 7–10)
 *
 * Calendrier de référence (année Y = septembre Y) :
 *
 *   1er Trimestre : 01/09/Y  → 17/12/Y   (vacances Noël : 18/12 – 04/01)
 *   2ème Trimestre : 05/01/(Y+1) → 27/03/(Y+1) (vacances Pâques : 28/03 – 11/04)
 *   3ème Trimestre : 13/04/(Y+1) → 02/07/(Y+1) (grandes vacances dès le 03/07)
 *
 *   1er Semestre : 01/09/Y  → 11/02/(Y+1)
 *   2ème Semestre : 12/02/(Y+1) → 02/07/(Y+1)
 */
class TermGenerationService
{
    public function generateForYear(SchoolYear $year): void
    {
        DB::transaction(function () use ($year): void {
            $startYear = $this->academicStartYear($year);

            foreach ($this->primaryTrimestreDefinitions($startYear) as $position => $definition) {
                $term = Term::query()->updateOrCreate(
                    [
                        'school_year_id'   => $year->id,
                        'position'         => $position,
                        'applicable_cycle' => Term::CYCLE_PRIMAIRE,
                    ],
                    [
                        'name'      => $definition['name'],
                        'term_type' => Term::TYPE_TRIMESTRE,
                        'starts_on' => $definition['starts_on'],
                        'ends_on'   => $definition['ends_on'],
                    ],
                );

                $this->generatePeriodsForTerm($term, $position, Term::TYPE_TRIMESTRE);
            }

            foreach ($this->secondarySemestreDefinitions($startYear) as $position => $definition) {
                $term = Term::query()->updateOrCreate(
                    [
                        'school_year_id'   => $year->id,
                        'position'         => $position,
                        'applicable_cycle' => Term::CYCLE_SECONDAIRE,
                    ],
                    [
                        'name'      => $definition['name'],
                        'term_type' => Term::TYPE_SEMESTRE,
                        'starts_on' => $definition['starts_on'],
                        'ends_on'   => $definition['ends_on'],
                    ],
                );

                $this->generatePeriodsForTerm($term, $position, Term::TYPE_SEMESTRE);
            }
        });
    }

    private function academicStartYear(SchoolYear $year): int
    {
        $start = CarbonImmutable::parse($year->starts_on);

        return $start->month >= 9 ? $start->year : $start->year - 1;
    }

    /**
     * @return array<int, array{name: string, starts_on: string, ends_on: string}>
     */
    private function primaryTrimestreDefinitions(int $year): array
    {
        return [
            1 => [
                'name'      => '1er Trimestre',
                'starts_on' => $this->on($year, 9, 1),
                'ends_on'   => $this->on($year, 12, 17),
            ],
            2 => [
                'name'      => '2ème Trimestre',
                'starts_on' => $this->on($year + 1, 1, 5),
                'ends_on'   => $this->on($year + 1, 3, 27),
            ],
            3 => [
                'name'      => '3ème Trimestre',
                'starts_on' => $this->on($year + 1, 4, 13),
                'ends_on'   => $this->on($year + 1, 7, 2),
            ],
        ];
    }

    /**
     * Positions 4 et 5 pour éviter le conflit d'unicité (school_year_id, position).
     *
     * @return array<int, array{name: string, starts_on: string, ends_on: string}>
     */
    private function secondarySemestreDefinitions(int $year): array
    {
        return [
            4 => [
                'name'      => '1er Semestre',
                'starts_on' => $this->on($year, 9, 1),
                'ends_on'   => $this->on($year + 1, 2, 11),
            ],
            5 => [
                'name'      => '2ème Semestre',
                'starts_on' => $this->on($year + 1, 2, 12),
                'ends_on'   => $this->on($year + 1, 7, 2),
            ],
        ];
    }

    private function on(int $year, int $month, int $day): string
    {
        return CarbonImmutable::create($year, $month, $day)->toDateString();
    }

    /**
     * Génère 2 périodes par terme (moitié gauche / moitié droite).
     * Positions globales dans l'année :
     *   - Trimestres 1–3 → périodes 1–6
     *   - Semestres 4–5  → périodes 7–10 (noms affichés P1–P4 côté secondaire)
     */
    private function generatePeriodsForTerm(Term $term, int $termPos, string $termType): void
    {
        $tStart = CarbonImmutable::parse($term->starts_on);
        $tEnd   = CarbonImmutable::parse($term->ends_on);
        $midDay = (int) floor($tStart->diffInDays($tEnd) / 2);

        $p1End   = $tStart->addDays($midDay);
        $p2Start = $p1End->addDay();

        if ($termType === Term::TYPE_SEMESTRE) {
            $semestreIndex = $termPos - 3;
            $globalBase = 6 + (($semestreIndex - 1) * 2) + 1;
            $nameBase = (($semestreIndex - 1) * 2) + 1;
        } else {
            $globalBase = ($termPos - 1) * 2 + 1;
            $nameBase = $globalBase;
        }

        $periods = [
            [
                'position'  => $globalBase,
                'name'      => 'Période '.$nameBase,
                'starts_on' => $tStart->toDateString(),
                'ends_on'   => $p1End->toDateString(),
            ],
            [
                'position'  => $globalBase + 1,
                'name'      => 'Période '.($nameBase + 1),
                'starts_on' => $p2Start->toDateString(),
                'ends_on'   => $tEnd->toDateString(),
            ],
        ];

        foreach ($periods as $periodData) {
            Period::query()->updateOrCreate(
                ['term_id' => $term->id, 'position' => $periodData['position']],
                [
                    'name'           => $periodData['name'],
                    'starts_on'      => $periodData['starts_on'],
                    'ends_on'        => $periodData['ends_on'],
                    'school_year_id' => $term->school_year_id,
                ],
            );
        }
    }
}
