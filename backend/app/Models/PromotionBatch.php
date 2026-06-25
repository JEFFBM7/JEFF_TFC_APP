<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de passage de classe : trace une exécution du passage d'une année à la
 * suivante (compteurs, auteur) et permet d'annuler le lot en bloc.
 */
#[Fillable([
    'from_school_year_id',
    'to_school_year_id',
    'run_by_id',
    'promoted_count',
    'repeated_count',
    'graduated_count',
    'status',
    'notes',
])]
class PromotionBatch extends Model
{
    use HasFactory;

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    /** @return BelongsTo<SchoolYear, $this> */
    public function fromSchoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'from_school_year_id');
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function toSchoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'to_school_year_id');
    }

    /** @return BelongsTo<User, $this> */
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
