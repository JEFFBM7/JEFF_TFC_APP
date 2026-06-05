<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'grade_id',
    'old_value',
    'new_value',
    'old_absent',
    'new_absent',
    'user_id',
])]
class GradeAudit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'old_absent' => 'boolean',
            'new_absent' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Grade, $this> */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
