<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_class_id', 'level_id', 'school_option_id', 'section', 'option', 'capacity', 'active'])]
class ClassRoom extends Model
{
    use HasFactory;

    protected $table = 'classrooms';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /** @return BelongsTo<Level, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** @return BelongsTo<SchoolOption, $this> */
    public function schoolOption(): BelongsTo
    {
        return $this->belongsTo(SchoolOption::class);
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'classroom_id');
    }

    /** @return HasMany<TeacherAssignment, $this> */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'classroom_id');
    }

    /** Nom complet : ex. « 1ère primaire A » ou « 1ère secondaire Mécanique A » */
    public function getFullNameAttribute(): string
    {
        $level = $this->level ?? $this->schoolClass?->level;
        $option = $this->option ?: $this->schoolClass?->schoolOption?->name;
        $parts = [$level?->name];

        if ($level?->cycle === Level::CYCLE_SECONDAIRE && filled($option)) {
            $parts[] = $option;
        }

        $parts[] = $this->section;

        return trim(implode(' ', array_filter($parts, fn ($part) => filled($part))));
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'classroom_subject', 'classroom_id', 'subject_id')
            ->withPivot('coefficient')
            ->withTimestamps();
    }
}
