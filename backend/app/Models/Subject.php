<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class Subject extends Model
{
    use HasFactory;

    /** @return BelongsToMany<ClassRoom, $this> */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'classroom_subject', 'subject_id', 'classroom_id')
            ->withPivot('coefficient')
            ->withTimestamps();
    }
}
