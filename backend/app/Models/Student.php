<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'classroom_id',
    'enrollment_school_year_id',
    'first_name',
    'last_name',
    'middle_name',
    'date_of_birth',
    'place_of_birth',
    'gender',
    'nationality',
    'registration_number',
    'photo_path',
    'enrollment_status',
    'order_number',
    'enrolled_on',
    'previous_school',
    'father_name',
    'mother_name',
    'legal_guardian_name',
    'guardian_relationship',
    'primary_phone',
    'secondary_phone',
    'parent_email',
    'residential_address',
    'father_profession',
    'mother_profession',
    'notes',
])]
class Student extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Tient à jour l'inscription de l'année courante (le cache classroom_id /
        // enrollment_school_year_id reste la référence pour l'année en cours).
        static::saved(function (Student $student): void {
            $student->syncCurrentEnrollment();
        });
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrolled_on' => 'date',
        ];
    }

    /**
     * Crée/actualise l'inscription correspondant à l'année portée par le cache.
     * Ne touche ni au statut ni à la décision d'une inscription déjà existante
     * (préserve les données posées par un passage de classe).
     */
    public function syncCurrentEnrollment(): void
    {
        $yearId = $this->enrollment_school_year_id;

        if ($yearId === null) {
            return;
        }

        $enrollment = $this->enrollments()->firstOrNew(['school_year_id' => $yearId]);
        $enrollment->classroom_id = $this->classroom_id;

        if (! $enrollment->exists) {
            $enrollment->enrolled_on = $this->enrolled_on;
            $enrollment->status = Enrollment::STATUS_ACTIVE;
        }

        $enrollment->save();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ClassRoom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function enrollmentSchoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'enrollment_school_year_id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return BelongsToMany<ParentProfile, $this> */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            ParentProfile::class,
            'parent_student',
            'student_id',
            'parent_profile_id',
        )->withPivot('relation')->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return collect([$this->last_name, $this->middle_name, $this->first_name])
            ->filter(fn ($part) => filled($part))
            ->implode(' ');
    }
}
