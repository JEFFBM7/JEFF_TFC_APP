<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'evaluation_id',
    'student_id',
    'value',
    'absent',
    'created_by',
    'updated_by',
])]
class Grade extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'absent' => 'boolean',
        ];
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<GradeAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(GradeAudit::class);
    }
}
