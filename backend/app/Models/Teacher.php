<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'teacher_type',
    'registration_number',
    'gender',
    'birth_date',
    'address',
    'grade',
    'contract_type',
    'hired_on',
    'speciality',
    'phone',
])]
class Teacher extends Model
{
    use HasFactory;

    public const TYPE_PRIMAIRE = 'primaire';

    public const TYPE_SECONDAIRE = 'secondaire';

    public const TYPES = [
        self::TYPE_PRIMAIRE,
        self::TYPE_SECONDAIRE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hired_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TeacherAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
