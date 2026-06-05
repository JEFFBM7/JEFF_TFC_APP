<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'abbreviation', 'cycle', 'order', 'has_options'])]
class Level extends Model
{
    use HasFactory;

    public const CYCLE_MATERNEL = 'maternel';

    public const CYCLE_PRIMAIRE = 'primaire';

    public const CYCLE_CTEB = 'cteb';

    public const CYCLE_SECONDAIRE = 'secondaire';

    public const CYCLES = [
        self::CYCLE_MATERNEL,
        self::CYCLE_PRIMAIRE,
        self::CYCLE_CTEB,
        self::CYCLE_SECONDAIRE,
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'has_options' => 'boolean',
        ];
    }

    /** @return HasMany<ClassRoom, $this> */
    public function classrooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class)->orderBy('option')->orderBy('section');
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class)->orderBy('name');
    }
}
