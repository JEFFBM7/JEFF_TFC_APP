<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_id',
    'classroom_id',
    'subject_id',
    'date',
    'status',
    'justified',
    'justification',
    'student_justification',
    'student_justified_at',
    'justified_by',
    'justified_at',
    'created_by',
])]
class Attendance extends Model
{
    use HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'justified' => 'boolean',
            'student_justified_at' => 'datetime',
            'justified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<ClassRoom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Absence encore concernée par l'alerte élève : délai de justification du jour non expiré
     * et justification élève non encore soumise.
     */
    public function countsForAbsenteeismAlert(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->status !== self::STATUS_ABSENT || $this->justified) {
            return false;
        }

        if (filled($this->student_justification)) {
            return false;
        }

        if ($this->date === null) {
            return false;
        }

        return $this->date->copy()->endOfDay()->greaterThanOrEqualTo($now);
    }
}
