<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'order'])]
class Level extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['order' => 'integer'];
    }

    /** @return HasMany<ClassRoom, $this> */
    public function classrooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class)->orderBy('section');
    }
}
