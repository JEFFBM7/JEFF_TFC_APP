<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Choix d'option du secondaire exprimé par un élève de 8e CTEB pour l'année
 * suivante. Une ligne par élève et par année de clôture.
 */
#[Fillable(['student_id', 'school_year_id', 'school_option_id', 'submitted_at'])]
class StudentOptionChoice extends Model
{
    /** Fenêtre de saisie : ouverte N jours avant la fin de l'année. */
    public const OPEN_DAYS_BEFORE_YEAR_END = 7;

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
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

    /** @return BelongsTo<SchoolOption, $this> */
    public function schoolOption(): BelongsTo
    {
        return $this->belongsTo(SchoolOption::class);
    }
}
