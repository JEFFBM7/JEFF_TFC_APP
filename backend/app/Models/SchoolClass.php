<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_year_id', 'level_id', 'school_option_id', 'name', 'active'])]
class SchoolClass extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function schoolOption(): BelongsTo
    {
        return $this->belongsTo(SchoolOption::class);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(ClassRoom::class)->orderBy('section');
    }
}
