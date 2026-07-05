<?php

namespace App\Models;

use App\Services\EnrollmentService;
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

            // L'année vient de devenir courante : on recale le cache des élèves
            // (classe + année) sur les inscriptions de cette année — c'est ainsi
            // qu'un passage de classe « prend effet » sur les écrans courants.
            if ($year->wasChanged('is_current')) {
                app(EnrollmentService::class)->syncStudentPointersForYear($year);
            }
        });

        // Garde-fou complémentaire sur le chemin Eloquent : la garantie « tous
        // chemins » repose désormais sur la FK classrooms.school_class_id en
        // cascade (cf. migration cascade_delete_classrooms_with_school_class) —
        // supprimer une année cascade vers ses SchoolClasses puis leurs divisions
        // au niveau base. Ce hook reste utile si l'enforcement des FK est un jour
        // désactivé et déclenche proprement les événements Eloquent des modèles.
        static::deleting(function (SchoolYear $year): void {
            ClassRoom::query()
                ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $year->id))
                ->delete();
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

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
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
