<?php

namespace App\Services;

use App\Models\SchoolYear;
use App\Models\Term;
use App\Support\DevCalendarContext;

class SchoolCalendarContextService
{
    /**
     * @param  list<string>|null  $allowedApplicableCycles  null = primaire + secondaire
     * @return array{
     *     school_year: array{id: int, name: string},
     *     today: string,
     *     entries: list<array<string, mixed>>,
     *     simulation: array<string, mixed>|null
     * }
     */
    public function build(SchoolYear $year, ?array $allowedApplicableCycles = null): array
    {
        $cycles = $allowedApplicableCycles ?? [Term::CYCLE_PRIMAIRE, Term::CYCLE_SECONDAIRE];

        $entries = [];
        foreach ($cycles as $cycle) {
            $entries[] = $this->entryForCycle($year, $cycle);
        }

        return [
            'school_year' => [
                'id' => $year->id,
                'name' => $year->name,
            ],
            'today' => DevCalendarContext::today()->toDateString(),
            'entries' => $entries,
            'simulation' => DevCalendarContext::meta(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryForCycle(SchoolYear $year, string $cycle): array
    {
        $activeTerm = DevCalendarContext::resolveTerm($year, $cycle);
        $activePeriod = DevCalendarContext::resolvePeriod($activeTerm);

        $status = 'none';
        $hint = null;

        if ($activeTerm !== null) {
            $status = $activePeriod !== null ? 'active' : 'between_periods';
            if ($status === 'between_periods') {
                $hint = sprintf(
                    '%s en cours, mais aucune période calendaire active aujourd\'hui.',
                    $activeTerm->name,
                );
            }
        } else {
            $today = DevCalendarContext::today();

            $nextTerm = Term::query()
                ->where('school_year_id', $year->id)
                ->where('applicable_cycle', $cycle)
                ->whereDate('starts_on', '>', $today)
                ->orderBy('starts_on')
                ->first();

            if ($nextTerm !== null) {
                $status = 'upcoming';
                $hint = sprintf(
                    'Prochain : %s à partir du %s.',
                    $nextTerm->name,
                    $nextTerm->starts_on->format('d/m/Y'),
                );
            } elseif (
                Term::query()
                    ->where('school_year_id', $year->id)
                    ->where('applicable_cycle', $cycle)
                    ->exists()
            ) {
                $status = 'ended';
                $hint = 'Tous les termes de ce cycle sont terminés pour cette année.';
            } else {
                $status = 'none';
                $hint = 'Aucun calendrier configuré pour ce cycle.';
            }
        }

        return [
            'cycle' => $cycle,
            'cycle_label' => $cycle === Term::CYCLE_SECONDAIRE
                ? 'Secondaire / CTEB'
                : 'Maternelle / Primaire',
            'term_type_label' => $cycle === Term::CYCLE_SECONDAIRE ? 'Semestre' : 'Trimestre',
            'status' => $status,
            'hint' => $hint,
            'term' => $activeTerm ? $this->termPayload($activeTerm) : null,
            'period' => $activePeriod ? $this->periodPayload($activePeriod) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function termPayload(Term $term): array
    {
        return [
            'id' => $term->id,
            'name' => $term->name,
            'term_type' => $term->term_type,
            'applicable_cycle' => $term->applicable_cycle,
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
            'is_closed' => $term->isClosed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(\App\Models\Period $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'position' => $period->position,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'is_closed' => $period->isClosed(),
        ];
    }
}
