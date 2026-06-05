<?php

namespace App\Support;

use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Simulation calendrier (local uniquement) via en-têtes HTTP de dev.
 */
class DevCalendarContext
{
    private static ?int $primaryTermId = null;

    private static ?int $primaryPeriodId = null;

    private static ?int $secondaryTermId = null;

    private static ?int $secondaryPeriodId = null;

    public static function applyFromRequest(Request $request): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        self::$primaryTermId = self::headerId($request, 'X-Dev-Calendar-Primary-Term-Id');
        self::$primaryPeriodId = self::headerId($request, 'X-Dev-Calendar-Primary-Period-Id');
        self::$secondaryTermId = self::headerId($request, 'X-Dev-Calendar-Secondary-Term-Id');
        self::$secondaryPeriodId = self::headerId($request, 'X-Dev-Calendar-Secondary-Period-Id');

        $reference = self::referenceDate();
        if ($reference !== null) {
            CarbonImmutable::setTestNow($reference);
        }
    }

    public static function reset(): void
    {
        self::$primaryTermId = null;
        self::$primaryPeriodId = null;
        self::$secondaryTermId = null;
        self::$secondaryPeriodId = null;
        CarbonImmutable::setTestNow();
    }

    public static function isActive(): bool
    {
        return self::$primaryTermId !== null
            || self::$primaryPeriodId !== null
            || self::$secondaryTermId !== null
            || self::$secondaryPeriodId !== null;
    }

    public static function today(): CarbonInterface
    {
        return self::referenceDate() ?? CarbonImmutable::today();
    }

    public static function referenceDate(): ?CarbonImmutable
    {
        foreach (self::referenceSources() as $source) {
            if ($source !== null) {
                return $source;
            }
        }

        return null;
    }

    public static function resolveTerm(SchoolYear $year, string $cycle): ?Term
    {
        $overrideId = $cycle === Term::CYCLE_SECONDAIRE
            ? self::$secondaryTermId
            : self::$primaryTermId;

        if ($overrideId !== null) {
            return Term::query()
                ->whereKey($overrideId)
                ->where('school_year_id', $year->id)
                ->where('applicable_cycle', $cycle)
                ->first();
        }

        $today = self::today();

        return Term::query()
            ->where('school_year_id', $year->id)
            ->where('applicable_cycle', $cycle)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('position')
            ->first();
    }

    public static function resolvePeriod(?Term $term): ?Period
    {
        if ($term === null) {
            return null;
        }

        $overrideId = $term->applicable_cycle === Term::CYCLE_SECONDAIRE
            ? self::$secondaryPeriodId
            : self::$primaryPeriodId;

        if ($overrideId !== null) {
            return Period::query()
                ->whereKey($overrideId)
                ->where('term_id', $term->id)
                ->first();
        }

        $today = self::today();

        return Period::query()
            ->where('term_id', $term->id)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('position')
            ->first();
    }

    /**
     * Terme « courant » pour le tableau de bord (premier cycle autorisé simulé ou réel).
     *
     * @param  list<string>|null  $allowedCycles
     */
    public static function resolveCurrentTermForDashboard(?SchoolYear $schoolYear, ?array $allowedCycles): ?Term
    {
        if ($schoolYear === null) {
            return null;
        }

        $cycles = $allowedCycles ?? [Term::CYCLE_PRIMAIRE, Term::CYCLE_SECONDAIRE];

        foreach ($cycles as $cycle) {
            $term = self::resolveTerm($schoolYear, $cycle);
            if ($term !== null) {
                return $term;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function meta(): ?array
    {
        if (! self::isActive()) {
            return null;
        }

        return [
            'active' => true,
            'reference_date' => self::referenceDate()?->toDateString(),
            'primary_term_id' => self::$primaryTermId,
            'primary_period_id' => self::$primaryPeriodId,
            'secondary_term_id' => self::$secondaryTermId,
            'secondary_period_id' => self::$secondaryPeriodId,
        ];
    }

    private static function headerId(Request $request, string $name): ?int
    {
        $value = $request->header($name);
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }

    /** @return list<?CarbonImmutable> */
    private static function referenceSources(): array
    {
        return [
            self::midpointForPeriod(self::$primaryPeriodId),
            self::midpointForTerm(self::$primaryTermId),
            self::midpointForPeriod(self::$secondaryPeriodId),
            self::midpointForTerm(self::$secondaryTermId),
        ];
    }

    private static function midpointForPeriod(?int $periodId): ?CarbonImmutable
    {
        if ($periodId === null) {
            return null;
        }

        $period = Period::query()->find($periodId);
        if ($period?->starts_on === null || $period->ends_on === null) {
            return null;
        }

        return self::midpoint($period->starts_on, $period->ends_on);
    }

    private static function midpointForTerm(?int $termId): ?CarbonImmutable
    {
        if ($termId === null) {
            return null;
        }

        $term = Term::query()->find($termId);
        if ($term?->starts_on === null || $term->ends_on === null) {
            return null;
        }

        return self::midpoint($term->starts_on, $term->ends_on);
    }

    private static function midpoint(CarbonInterface $start, CarbonInterface $end): CarbonImmutable
    {
        $startDay = CarbonImmutable::parse($start)->startOfDay();
        $endDay = CarbonImmutable::parse($end)->startOfDay();
        $days = max(0, $startDay->diffInDays($endDay));

        return $startDay->addDays((int) floor($days / 2));
    }
}
