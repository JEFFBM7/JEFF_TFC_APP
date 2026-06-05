<?php

namespace App\Models;

use App\Enums\UserRole;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'classroom_id',
    'subject_id',
    'term_id',
    'period_id',
    'teacher_id',
    'name',
    'type',
    'held_on',
    'max_value',
    'published_at',
])]
class Evaluation extends Model
{
    use HasFactory;

    public const TYPE_INTERROGATION = 'interrogation';
    public const TYPE_CONTROLE = 'controle';
    public const TYPE_DEVOIR = 'devoir';
    public const TYPE_EXAMEN = 'examen';
    public const TYPE_ORAL = 'oral';
    public const TYPE_PROJET = 'projet';

    public const COMPONENT_CONTINUOUS = 'continuous';
    public const COMPONENT_EXAM = 'exam';

    public const CONTINUOUS_WEIGHT = 0.4;
    public const EXAM_WEIGHT = 0.6;

    public const TYPES = [
        self::TYPE_INTERROGATION,
        self::TYPE_CONTROLE,
        self::TYPE_DEVOIR,
        self::TYPE_EXAMEN,
        self::TYPE_ORAL,
        self::TYPE_PROJET,
    ];

    public const CONTINUOUS_TYPES = [
        self::TYPE_INTERROGATION,
        self::TYPE_CONTROLE,
        self::TYPE_DEVOIR,
        self::TYPE_ORAL,
        self::TYPE_PROJET,
    ];

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            'max_value' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function component(): string
    {
        return self::componentForType($this->type);
    }

    public static function componentForType(?string $type): string
    {
        return $type === self::TYPE_EXAMEN
            ? self::COMPONENT_EXAM
            : self::COMPONENT_CONTINUOUS;
    }

    /** @return list<string> */
    public static function typesForRole(UserRole|string|null $role): array
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;

        return match ($roleValue) {
            'admin' => [self::TYPE_EXAMEN],
            'enseignant' => self::teacherTypes(),
            default => [],
        };
    }

    /** @return list<string> */
    public static function teacherTypes(): array
    {
        return array_values(array_filter(
            self::TYPES,
            fn (string $type) => $type !== self::TYPE_EXAMEN,
        ));
    }

    public function isExam(): bool
    {
        return $this->type === self::TYPE_EXAMEN;
    }

    public static function roleCanManageType(UserRole|string|null $role, ?string $type): bool
    {
        if ($role === null || $type === null) {
            return false;
        }

        return in_array($type, self::typesForRole($role), true);
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            self::TYPE_INTERROGATION, self::TYPE_CONTROLE => 'Interrogation',
            self::TYPE_DEVOIR => 'Devoir',
            self::TYPE_EXAMEN => 'Examen de période',
            self::TYPE_ORAL => 'Oral',
            self::TYPE_PROJET => 'Projet',
            default => (string) $type,
        };
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

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /** @return HasMany<Grade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
