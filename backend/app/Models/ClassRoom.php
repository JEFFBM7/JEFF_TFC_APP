<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['level_id', 'section', 'capacity'])]
class ClassRoom extends Model
{
    use HasFactory;

    protected $table = 'classrooms';

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    /** @return BelongsTo<Level, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** Nom complet : ex. « 6ème A » */
    public function getFullNameAttribute(): string
    {
        return trim($this->level?->name . ' ' . $this->section);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'classroom_subject', 'classroom_id', 'subject_id')
            ->withPivot('coefficient')
            ->withTimestamps();
    }
}
