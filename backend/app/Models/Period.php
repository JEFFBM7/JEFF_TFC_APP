<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['term_id', 'school_year_id', 'name', 'position', 'starts_on', 'ends_on', 'closed_at'])]
class Period extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'school_year_id' => 'integer',
            'position' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Period $period): void {
            if ($period->term_id === null) {
                return;
            }

            if ($period->isDirty('term_id') || $period->school_year_id === null) {
                $period->school_year_id = Term::query()
                    ->whereKey($period->term_id)
                    ->value('school_year_id');
            }
        });
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * @return array<int, int>
     */
    public static function positionsForTerm(Term $term): array
    {
        if ($term->term_type === Term::TYPE_SEMESTRE) {
            $semestreIndex = max(1, (int) $term->position - 3);
            $first = 6 + (($semestreIndex - 1) * 2) + 1;

            return [$first, $first + 1];
        }

        $first = (((int) $term->position - 1) * 2) + 1;

        return [$first, $first + 1];
    }

    /**
     * @param  Builder<Period>  $query
     * @return Builder<Period>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString());
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return HasMany<Evaluation, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }
}
