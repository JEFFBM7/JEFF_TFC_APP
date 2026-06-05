<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrolled_on' => 'date',
        ];
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
