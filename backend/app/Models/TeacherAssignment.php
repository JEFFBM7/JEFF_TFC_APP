<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['teacher_id', 'classroom_id', 'subject_id', 'school_year_id', 'term_id', 'weekly_hours', 'is_main'])]
class TeacherAssignment extends Model
{
    use HasFactory;

    protected $table = 'teacher_assignments';

    protected $casts = [
        'is_main' => 'boolean',
        'weekly_hours' => 'decimal:2',
    ];

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
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

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
