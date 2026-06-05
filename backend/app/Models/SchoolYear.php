<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_on', 'ends_on', 'is_current', 'closed_at', 'archived_at', 'archived_by_id'])]
class SchoolYear extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (SchoolYear $year): void {
            if (! $year->is_current) {
                return;
            }

            static::withoutEvents(function () use ($year): void {
                static::query()
                    ->whereKeyNot($year->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('position');
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * @param  Builder<SchoolYear>  $query
     * @return Builder<SchoolYear>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('is_current', true)
            ->whereNull('archived_at')
            ->orderByDesc('starts_on')
            ->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
