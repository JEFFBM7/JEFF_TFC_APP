<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription d'un élève pour une année scolaire donnée.
 *
 * Source de vérité de l'historique : une ligne par (élève × année). Le couple
 * students.classroom_id / enrollment_school_year_id n'est qu'un cache de
 * l'inscription courante, tenu à jour par {@see Student::syncCurrentEnrollment()}.
 */
#[Fillable([
    'student_id',
    'school_year_id',
    'classroom_id',
    'status',
    'decision',
    'result_average',
    'previous_enrollment_id',
    'promotion_batch_id',
    'enrolled_on',
    'left_on',
    'decided_at',
    'decided_by_id',
    'notes',
])]
class Enrollment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'actif';

    public const STATUS_PROMOTED = 'admis';

    public const STATUS_REPEATING = 'redouble';

    public const STATUS_EXCLUDED = 'exclu';

    public const STATUS_TRANSFERRED = 'transfere';

    public const STATUS_GRADUATED = 'diplome';

    public const STATUS_DROPPED = 'abandon';

    public const DECISION_PROMOTED = 'promu';

    public const DECISION_REPEAT = 'redouble';

    public const DECISION_ORIENTED = 'oriente';

    protected function casts(): array
    {
        return [
            'result_average' => 'float',
            'enrolled_on' => 'date',
            'left_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<ClassRoom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function previousEnrollment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_enrollment_id');
    }

    /** @return BelongsTo<PromotionBatch, $this> */
    public function promotionBatch(): BelongsTo
    {
        return $this->belongsTo(PromotionBatch::class);
    }

    /**
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeForYear(Builder $query, int $schoolYearId): Builder
    {
        return $query->where('school_year_id', $schoolYearId);
    }

    /**
     * @param  Builder<Enrollment>  $query
     * @return Builder<Enrollment>
     */
    public function scopeForClassroom(Builder $query, int $classroomId): Builder
    {
        return $query->where('classroom_id', $classroomId);
    }
}
