<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['classroom_id', 'subject_id', 'teacher_id', 'school_year_id', 'day_of_week', 'starts_at', 'ends_at', 'room'])]
class TimetableSlot extends Model
{
    use HasFactory;

    protected $table = 'timetable_slots';

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    /** @return BelongsTo<ClassRoom, $this> */
    public function classroom(): BelongsTo { return $this->belongsTo(ClassRoom::class); }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo { return $this->belongsTo(SchoolYear::class); }
}
