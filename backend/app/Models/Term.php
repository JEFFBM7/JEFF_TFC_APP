<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_year_id', 'name', 'position', 'starts_on', 'ends_on', 'closed_at', 'term_type', 'applicable_cycle'])]
class Term extends Model
{
    use HasFactory;

    /** Types de découpage */
    public const TYPE_TRIMESTRE  = 'trimestre';
    public const TYPE_SEMESTRE   = 'semestre';
    public const TYPES = [self::TYPE_TRIMESTRE, self::TYPE_SEMESTRE];

    /** Cycles auxquels s'applique le term */
    public const CYCLE_PRIMAIRE    = 'primaire';
    public const CYCLE_SECONDAIRE  = 'secondaire';
    public const CYCLES = [self::CYCLE_PRIMAIRE, self::CYCLE_SECONDAIRE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'position'  => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isTrimestre(): bool
    {
        return $this->term_type === self::TYPE_TRIMESTRE;
    }

    public function isSemestre(): bool
    {
        return $this->term_type === self::TYPE_SEMESTRE;
    }

    /** Label humain du type (ex: "Trimestre", "Semestre") */
    public function typeLabel(): string
    {
        return $this->term_type === self::TYPE_SEMESTRE ? 'Semestre' : 'Trimestre';
    }

    /**
     * Regroupe les niveaux scolaires vers le calendrier applicable.
     * Maternelle + Primaire → trimestres « primaire »
     * Secondaire + CTEB     → semestres « secondaire »
     */
    public static function applicableCycleForLevelCycle(?string $levelCycle): string
    {
        return in_array($levelCycle, [Level::CYCLE_SECONDAIRE, Level::CYCLE_CTEB], true)
            ? self::CYCLE_SECONDAIRE
            : self::CYCLE_PRIMAIRE;
    }

    public function cycleGroupLabel(): string
    {
        return $this->applicable_cycle === self::CYCLE_SECONDAIRE
            ? 'Secondaire / CTEB'
            : 'Maternelle / Primaire';
    }

    /**
     * @return BelongsTo<SchoolYear, $this>
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return HasMany<Period, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('position');
    }

    /** @return HasMany<Evaluation, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }
}
