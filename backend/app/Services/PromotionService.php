<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ClassRoom;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Level;
use App\Models\PromotionBatch;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Passage de classe : à partir des inscriptions d'une année, propose
 * (preview) puis applique (commit) la promotion des admis en classe
 * supérieure et le redoublement des autres, en réutilisant les comptes.
 *
 * - preview() : aucune écriture, calcule des décisions par défaut.
 * - commit()  : crée les inscriptions de l'année cible (transaction, idempotent).
 * - rollback(): annule un lot tant que l'année cible n'est pas devenue courante.
 */
class PromotionService
{
    public const DECISION_PROMOTED = Enrollment::DECISION_PROMOTED;

    public const DECISION_REPEAT = Enrollment::DECISION_REPEAT;

    /** Seuil de réussite (sur 20), configurable. */
    public function threshold(): float
    {
        return (float) AppSetting::get('promotion.pass_average_threshold', 10.0);
    }

    /**
     * Aperçu (dry-run) du passage de `from` vers `to`.
     *
     * @return array<string, mixed>
     */
    public function preview(SchoolYear $from, SchoolYear $to): array
    {
        $threshold = $this->threshold();
        $termIds = $from->terms()->pluck('id');
        $toSchoolClasses = SchoolClass::query()
            ->where('school_year_id', $to->id)
            ->with('divisions')
            ->get();

        $alreadyByStudent = Enrollment::query()
            ->where('school_year_id', $to->id)
            ->pluck('classroom_id', 'student_id');

        $sources = Enrollment::query()
            ->where('school_year_id', $from->id)
            ->with(['student', 'classroom.level', 'classroom.schoolOption'])
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->classroom?->full_name, $e->student?->last_name])
            ->values();

        $tally = [];
        $rows = [];
        $promote = 0;
        $repeat = 0;
        $graduate = 0;
        $toReview = 0;

        foreach ($sources as $source) {
            $average = $this->averageFor((int) $source->student_id, $termIds);
            $suggestedDecision = $average === null
                ? null
                : ($average >= $threshold ? self::DECISION_PROMOTED : self::DECISION_REPEAT);

            $resolution = $this->resolveTarget(
                $source,
                $suggestedDecision ?? self::DECISION_PROMOTED,
                $toSchoolClasses,
                $tally,
            );

            if ($resolution['status'] === 'graduate') {
                $graduate++;
            } elseif ($average === null || $resolution['status'] !== 'ok') {
                $toReview++;
            } elseif ($suggestedDecision === self::DECISION_PROMOTED) {
                $promote++;
            } else {
                $repeat++;
            }

            $rows[] = [
                'enrollment_id' => $source->id,
                'student' => [
                    'id' => $source->student_id,
                    'full_name' => $source->student?->full_name,
                    'registration_number' => $source->student?->registration_number,
                ],
                'current_classroom' => [
                    'id' => $source->classroom_id,
                    'full_name' => $source->classroom?->full_name,
                ],
                'result_average' => $average,
                'suggested_decision' => $suggestedDecision,
                'resolution_status' => $resolution['status'],
                'target_level' => $resolution['level']
                    ? ['id' => $resolution['level']->id, 'name' => $resolution['level']->name]
                    : null,
                'target_classroom_id' => $resolution['classroom_id'],
                'warnings' => $resolution['warnings'],
                'already_enrolled_classroom_id' => $alreadyByStudent[$source->student_id] ?? null,
            ];
        }

        return [
            'threshold' => $threshold,
            'from_school_year' => ['id' => $from->id, 'name' => $from->name],
            'to_school_year' => ['id' => $to->id, 'name' => $to->name],
            'summary' => [
                'total' => count($rows),
                'promote' => $promote,
                'repeat' => $repeat,
                'graduate' => $graduate,
                'to_review' => $toReview,
                'already_promoted' => $alreadyByStudent->count(),
            ],
            'students' => $rows,
            'available_classrooms' => $this->availableClassrooms($to),
        ];
    }

    /**
     * Applique les décisions (issues du preview, éventuellement éditées).
     *
     * @param  array<int, array{enrollment_id:int, decision:?string, target_classroom_id:?int}>  $decisions
     */
    public function commit(SchoolYear $from, SchoolYear $to, array $decisions, ?int $userId): PromotionBatch
    {
        return DB::transaction(function () use ($from, $to, $decisions, $userId): PromotionBatch {
            $batch = PromotionBatch::query()->create([
                'from_school_year_id' => $from->id,
                'to_school_year_id' => $to->id,
                'run_by_id' => $userId,
                'status' => PromotionBatch::STATUS_COMMITTED,
            ]);

            $termIds = $from->terms()->pluck('id');
            $promoted = 0;
            $repeated = 0;
            $graduated = 0;

            foreach ($decisions as $decision) {
                $source = Enrollment::query()
                    ->where('id', (int) ($decision['enrollment_id'] ?? 0))
                    ->where('school_year_id', $from->id)
                    ->first();

                $choice = $decision['decision'] ?? null;

                if ($source === null || $choice === null || $choice === 'skip') {
                    continue;
                }

                $average = $this->averageFor((int) $source->student_id, $termIds);

                if ($choice === Enrollment::STATUS_GRADUATED || $choice === 'diplome') {
                    $source->update([
                        'status' => Enrollment::STATUS_GRADUATED,
                        'decision' => self::DECISION_PROMOTED,
                        'result_average' => $average,
                        'decided_at' => now(),
                        'decided_by_id' => $userId,
                    ]);
                    $graduated++;

                    continue;
                }

                $targetClassroomId = $decision['target_classroom_id'] ?? null;

                if ($targetClassroomId === null || ! $this->classroomBelongsToYear((int) $targetClassroomId, $to)) {
                    continue;
                }

                Enrollment::query()->updateOrCreate(
                    ['student_id' => $source->student_id, 'school_year_id' => $to->id],
                    [
                        'classroom_id' => (int) $targetClassroomId,
                        'status' => Enrollment::STATUS_ACTIVE,
                        'previous_enrollment_id' => $source->id,
                        'promotion_batch_id' => $batch->id,
                        'enrolled_on' => $to->starts_on,
                    ],
                );

                $isRepeat = $choice === self::DECISION_REPEAT;
                $source->update([
                    'status' => $isRepeat ? Enrollment::STATUS_REPEATING : Enrollment::STATUS_PROMOTED,
                    'decision' => $isRepeat ? self::DECISION_REPEAT : self::DECISION_PROMOTED,
                    'result_average' => $average,
                    'decided_at' => now(),
                    'decided_by_id' => $userId,
                ]);

                $isRepeat ? $repeated++ : $promoted++;
            }

            $batch->update([
                'promoted_count' => $promoted,
                'repeated_count' => $repeated,
                'graduated_count' => $graduated,
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Annule un lot : supprime les inscriptions créées dans l'année cible et
     * remet les inscriptions sources dans leur état initial.
     */
    public function rollback(PromotionBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $created = Enrollment::query()->where('promotion_batch_id', $batch->id)->get();
            $sourceIds = $created->pluck('previous_enrollment_id')->filter()->unique()->values();

            Enrollment::query()->where('promotion_batch_id', $batch->id)->delete();

            Enrollment::query()->whereIn('id', $sourceIds)->update([
                'status' => Enrollment::STATUS_ACTIVE,
                'decision' => null,
                'decided_at' => null,
                'decided_by_id' => null,
            ]);

            // Diplômés du lot : pas d'inscription créée dans l'année cible.
            $studentsInTo = Enrollment::query()
                ->where('school_year_id', $batch->to_school_year_id)
                ->pluck('student_id');

            Enrollment::query()
                ->where('school_year_id', $batch->from_school_year_id)
                ->where('status', Enrollment::STATUS_GRADUATED)
                ->whereNotNull('decided_at')
                ->whereNotIn('student_id', $studentsInTo)
                ->update([
                    'status' => Enrollment::STATUS_ACTIVE,
                    'decision' => null,
                    'decided_at' => null,
                    'decided_by_id' => null,
                ]);

            $batch->update(['status' => PromotionBatch::STATUS_ROLLED_BACK]);
        });
    }

    /**
     * Détermine la classe cible d'un élève selon la décision.
     *
     * @param  Collection<int, SchoolClass>  $toSchoolClasses
     * @param  array<int, int>  $tally  Charge en cours par division (répartition)
     * @return array{status:string, level:?Level, classroom_id:?int, warnings:array<int, string>}
     */
    private function resolveTarget(Enrollment $source, string $decision, Collection $toSchoolClasses, array &$tally): array
    {
        $sourceClassroom = $source->classroom;
        $sourceLevel = $sourceClassroom?->level;

        if ($sourceLevel === null) {
            return ['status' => 'needs_class', 'level' => null, 'classroom_id' => null, 'warnings' => ['Classe d’origine inconnue.']];
        }

        if ($decision === self::DECISION_REPEAT) {
            $targetLevel = $sourceLevel;
        } else {
            $targetLevel = $this->nextLevel($sourceLevel);

            if ($targetLevel === null) {
                return ['status' => 'graduate', 'level' => null, 'classroom_id' => null, 'warnings' => []];
            }
        }

        $optionId = $targetLevel->has_options ? $sourceClassroom->school_option_id : null;

        if ($targetLevel->has_options && $optionId === null) {
            return [
                'status' => 'needs_option',
                'level' => $targetLevel,
                'classroom_id' => null,
                'warnings' => ['Entrée au secondaire : choisir l’option/section manuellement.'],
            ];
        }

        $schoolClass = $toSchoolClasses->first(
            fn (SchoolClass $sc) => $sc->level_id === $targetLevel->id
                && (string) $sc->school_option_id === (string) $optionId,
        );

        if ($schoolClass === null) {
            return [
                'status' => 'needs_class',
                'level' => $targetLevel,
                'classroom_id' => null,
                'warnings' => ['Classe cible absente dans l’année destination — la générer d’abord.'],
            ];
        }

        $division = $this->pickDivision($schoolClass->divisions, $sourceClassroom->section, $tally);

        if ($division === null) {
            return [
                'status' => 'needs_class',
                'level' => $targetLevel,
                'classroom_id' => null,
                'warnings' => ['Aucune division disponible pour la classe cible.'],
            ];
        }

        return ['status' => 'ok', 'level' => $targetLevel, 'classroom_id' => $division->id, 'warnings' => []];
    }

    /** Niveau immédiatement supérieur par `order` (gère les sauts entre cycles). */
    private function nextLevel(Level $level): ?Level
    {
        return Level::query()
            ->where('order', '>', $level->order)
            ->orderBy('order')
            ->first();
    }

    /**
     * Choisit une division : conserve la section d'origine si possible (et de
     * la place), sinon la division la moins remplie dans la capacité.
     *
     * @param  Collection<int, ClassRoom>  $divisions
     * @param  array<int, int>  $tally
     */
    private function pickDivision(Collection $divisions, ?string $preferred, array &$tally): ?ClassRoom
    {
        $active = $divisions->where('active', true)->values();

        if ($active->isEmpty()) {
            $active = $divisions->values();
        }

        if ($active->isEmpty()) {
            return null;
        }

        $load = fn (ClassRoom $c): int => $tally[$c->id] ?? 0;
        $hasRoom = fn (ClassRoom $c): bool => $load($c) < (int) ($c->capacity ?? 40);

        $same = $preferred ? $active->firstWhere('section', $preferred) : null;
        if ($same instanceof ClassRoom && $hasRoom($same)) {
            $tally[$same->id] = $load($same) + 1;

            return $same;
        }

        $withRoom = $active->filter($hasRoom)->sortBy($load)->values();
        $chosen = $withRoom->first() ?? $active->sortBy($load)->first();
        $tally[$chosen->id] = $load($chosen) + 1;

        return $chosen;
    }

    /** Moyenne annuelle normalisée sur 20 d'un élève (mêmes règles que les stats). */
    private function averageFor(int $studentId, Collection $termIds): ?float
    {
        if ($termIds->isEmpty()) {
            return null;
        }

        $average = Grade::query()
            ->join('evaluations', 'evaluations.id', '=', 'grades.evaluation_id')
            ->whereIn('evaluations.term_id', $termIds)
            ->where('grades.student_id', $studentId)
            ->where('grades.absent', false)
            ->whereNotNull('grades.value')
            ->where('evaluations.max_value', '>', 0)
            ->selectRaw('AVG((grades.value * 20.0) / evaluations.max_value) as average')
            ->value('average');

        return $average !== null ? round((float) $average, 2) : null;
    }

    private function classroomBelongsToYear(int $classroomId, SchoolYear $year): bool
    {
        return ClassRoom::query()
            ->where('id', $classroomId)
            ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $year->id))
            ->exists();
    }

    /**
     * Classes disponibles dans l'année cible (pour l'édition manuelle), avec
     * leur charge actuelle.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableClassrooms(SchoolYear $to): array
    {
        $loads = Enrollment::query()
            ->where('school_year_id', $to->id)
            ->whereNotNull('classroom_id')
            ->selectRaw('classroom_id, COUNT(*) as c')
            ->groupBy('classroom_id')
            ->pluck('c', 'classroom_id');

        return ClassRoom::query()
            ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $to->id))
            ->with(['level', 'schoolClass'])
            ->get()
            ->sortBy('full_name')
            ->map(fn (ClassRoom $c) => [
                'id' => $c->id,
                'full_name' => $c->full_name,
                'level_id' => $c->level_id ?? $c->schoolClass?->level_id,
                'capacity' => (int) ($c->capacity ?? 40),
                'enrolled' => (int) ($loads[$c->id] ?? 0),
            ])
            ->values()
            ->all();
    }
}
