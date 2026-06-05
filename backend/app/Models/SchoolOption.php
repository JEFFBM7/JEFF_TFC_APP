<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'abbreviation', 'cycle', 'filiere'])]
class SchoolOption extends Model
{
    use HasFactory;

    public const FILIERE_GENERALE = 'generale';

    public const FILIERE_TECHNIQUE = 'technique';

    public const FILIERE_PROFESSIONNELLE = 'professionnelle';

    public const FILIERES = [
        self::FILIERE_GENERALE,
        self::FILIERE_TECHNIQUE,
        self::FILIERE_PROFESSIONNELLE,
    ];

    /** @return HasMany<ClassRoom, $this> */
    public function classrooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class);
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
