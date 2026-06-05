<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description', 'default_coefficient', 'evaluation_type', 'status'])]
class Subject extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'default_coefficient' => 'decimal:2',
        ];
    }

    /** @return BelongsToMany<ClassRoom, $this> */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'classroom_subject', 'subject_id', 'classroom_id')
            ->withPivot('coefficient')
            ->withTimestamps();
    }

    /** @return HasMany<TeacherAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
