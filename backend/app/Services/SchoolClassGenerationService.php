<?php

namespace App\Services;

use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Support\SchoolOptionCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class SchoolClassGenerationService
{
    public function ensureFixedStructure(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasTable('levels')) {
                return;
            }

            $levelHasAbbreviation = Schema::hasColumn('levels', 'abbreviation');
            $levelHasOptions = Schema::hasColumn('levels', 'has_options');

            foreach ($this->levelRows() as $row) {
                $values = [
                    'cycle' => $row['cycle'],
                    'order' => $row['order'],
                ];

                if ($levelHasAbbreviation) {
                    $values['abbreviation'] = $row['abbreviation'];
                }

                if ($levelHasOptions) {
                    $values['has_options'] = $row['has_options'];
                }

                Level::query()->updateOrCreate(
                    ['name' => $row['name']],
                    $values,
                );
            }

            if (! Schema::hasTable('school_options')) {
                return;
            }

            $optionHasAbbreviation = Schema::hasColumn('school_options', 'abbreviation');
            $optionHasCycle = Schema::hasColumn('school_options', 'cycle');
            $optionHasFiliere = Schema::hasColumn('school_options', 'filiere');

            foreach ($this->optionRows() as $row) {
                $values = [];

                if ($optionHasAbbreviation) {
                    $values['abbreviation'] = $row['abbreviation'];
                }

                if ($optionHasCycle) {
                    $values['cycle'] = Level::CYCLE_SECONDAIRE;
                }

                if ($optionHasFiliere) {
                    $values['filiere'] = $row['filiere'];
                }

                SchoolOption::query()->updateOrCreate(
                    ['name' => $row['name']],
                    $values,
                );
            }
        });
    }

    /**
     * Génère les classes de base de l'année.
     *
     * @param  array<int, int>|null  $optionIds  Si fourni, seules ces options secondaires
     *                                           sont générées (les cycles sans option — M1
     *                                           à 8e CTEB — le sont toujours automatiquement).
     *                                           null = toutes les options (comportement legacy).
     */
    public function generateBaseClasses(SchoolYear $schoolYear, ?array $optionIds = null): Collection
    {
        $this->ensureFixedStructure();

        if (! Schema::hasTable('school_classes')) {
            return collect();
        }

        return DB::transaction(function () use ($schoolYear, $optionIds): Collection {
            $created = collect();
            $levels = Level::query()->orderBy('order')->orderBy('name')->get();
            $secondaryOptionsQuery = SchoolOption::query()->orderBy('name');

            if (Schema::hasColumn('school_options', 'cycle')) {
                $secondaryOptionsQuery->where(function ($query): void {
                    $query->where('cycle', Level::CYCLE_SECONDAIRE)->orWhereNull('cycle');
                });
            }

            $secondaryOptions = $secondaryOptionsQuery->get();

            if ($optionIds !== null) {
                $secondaryOptions = $secondaryOptions
                    ->whereIn('id', array_map('intval', $optionIds))
                    ->values();
            }

            foreach ($levels as $level) {
                if (! $level->has_options) {
                    $created->push($this->upsertSchoolClass($schoolYear, $level, null));

                    continue;
                }

                foreach ($secondaryOptions as $option) {
                    $created->push($this->upsertSchoolClass($schoolYear, $level, $option));
                }
            }

            if ($optionIds !== null) {
                $this->pruneUnselectedSecondaryClasses(
                    $schoolYear,
                    $secondaryOptions->pluck('id')->all(),
                );
            }

            return $created->values();
        });
    }

    /**
     * Supprime les classes secondaires de l'année dont l'option n'est plus
     * sélectionnée, à condition qu'elles soient vides (aucun élève ni
     * inscription dans leurs divisions).
     */
    private function pruneUnselectedSecondaryClasses(SchoolYear $schoolYear, array $keptOptionIds): void
    {
        $classes = SchoolClass::query()
            ->where('school_year_id', $schoolYear->id)
            ->whereNotNull('school_option_id')
            ->whereNotIn('school_option_id', $keptOptionIds)
            ->with(['divisions' => fn ($query) => $query->withCount(['students', 'enrollments'])])
            ->get();

        foreach ($classes as $class) {
            $hasData = $class->divisions->contains(
                fn (ClassRoom $division) => ($division->students_count ?? 0) > 0
                    || ($division->enrollments_count ?? 0) > 0,
            );

            if ($hasData) {
                continue;
            }

            $class->divisions->each(fn (ClassRoom $division) => $division->delete());
            $class->delete();
        }
    }

    /**
     * Crée (ou réutilise) une classe pour un niveau (+ option si secondaire),
     * puis y ajoute le nombre de divisions demandé.
     */
    public function createClass(
        SchoolYear $schoolYear,
        Level $level,
        ?SchoolOption $option,
        int $divisions = 1,
        int $capacity = 40,
    ): SchoolClass {
        return DB::transaction(function () use ($schoolYear, $level, $option, $divisions, $capacity): SchoolClass {
            $schoolClass = $this->upsertSchoolClass(
                $schoolYear,
                $level,
                $level->has_options ? $option : null,
            );

            for ($i = 0, $n = max(1, $divisions); $i < $n; $i++) {
                $this->addNextDivision($schoolClass, $capacity);
            }

            return $schoolClass->fresh(['level', 'schoolOption']);
        });
    }

    public function addDivisions(SchoolClass $schoolClass, int $count, int $capacity): Collection
    {
        if ($count < 1 || $count > 26) {
            throw new InvalidArgumentException('Le nombre de divisions doit être compris entre 1 et 26.');
        }

        return DB::transaction(function () use ($schoolClass, $count, $capacity): Collection {
            $divisions = collect();

            for ($index = 1; $index <= $count; $index++) {
                $divisions->push($this->upsertDivision($schoolClass, chr(64 + $index), $capacity));
            }

            return $divisions->values();
        });
    }

    public function addNextDivision(SchoolClass $schoolClass, int $capacity): ClassRoom
    {
        return DB::transaction(function () use ($schoolClass, $capacity): ClassRoom {
            $last = ClassRoom::query()
                ->where('school_class_id', $schoolClass->id)
                ->orderByDesc('section')
                ->value('section');

            $next = $last ? chr(ord((string) $last) + 1) : 'A';

            if ($next < 'A' || $next > 'Z') {
                throw new InvalidArgumentException('Impossible d’ajouter plus de 26 divisions.');
            }

            return $this->upsertDivision($schoolClass, $next, $capacity);
        });
    }

    private function upsertSchoolClass(SchoolYear $schoolYear, Level $level, ?SchoolOption $option): SchoolClass
    {
        $name = $level->has_options && $option
            ? trim(($level->abbreviation ?: $level->name).' - '.($option->abbreviation ?: $option->name))
            : (string) ($level->abbreviation ?: $level->name);

        return SchoolClass::query()->updateOrCreate(
            [
                'school_year_id' => $schoolYear->id,
                'level_id' => $level->id,
                'school_option_id' => $option?->id,
            ],
            [
                'name' => $name,
                'active' => true,
            ],
        );
    }

    private function upsertDivision(SchoolClass $schoolClass, string $section, int $capacity): ClassRoom
    {
        $schoolClass->loadMissing(['level', 'schoolOption']);

        return ClassRoom::query()->updateOrCreate(
            [
                'school_class_id' => $schoolClass->id,
                'section' => $section,
            ],
            [
                'level_id' => $schoolClass->level_id,
                'school_option_id' => $schoolClass->school_option_id,
                'option' => $schoolClass->schoolOption?->name ?? '',
                'capacity' => $capacity,
                'active' => true,
            ],
        );
    }

    private function levelRows(): array
    {
        return [
            ['name' => '1ère maternelle', 'abbreviation' => 'M1', 'cycle' => Level::CYCLE_MATERNEL, 'order' => 1, 'has_options' => false],
            ['name' => '2e maternelle', 'abbreviation' => 'M2', 'cycle' => Level::CYCLE_MATERNEL, 'order' => 2, 'has_options' => false],
            ['name' => '3e maternelle', 'abbreviation' => 'M3', 'cycle' => Level::CYCLE_MATERNEL, 'order' => 3, 'has_options' => false],
            ['name' => '1ère primaire', 'abbreviation' => '1P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 10, 'has_options' => false],
            ['name' => '2e primaire', 'abbreviation' => '2P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 11, 'has_options' => false],
            ['name' => '3e primaire', 'abbreviation' => '3P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 12, 'has_options' => false],
            ['name' => '4e primaire', 'abbreviation' => '4P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 13, 'has_options' => false],
            ['name' => '5e primaire', 'abbreviation' => '5P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 14, 'has_options' => false],
            ['name' => '6e primaire', 'abbreviation' => '6P', 'cycle' => Level::CYCLE_PRIMAIRE, 'order' => 15, 'has_options' => false],
            ['name' => '7e CTEB', 'abbreviation' => '7EB', 'cycle' => Level::CYCLE_CTEB, 'order' => 20, 'has_options' => false],
            ['name' => '8e CTEB', 'abbreviation' => '8EB', 'cycle' => Level::CYCLE_CTEB, 'order' => 21, 'has_options' => false],
            ['name' => '1ère secondaire', 'abbreviation' => '9S', 'cycle' => Level::CYCLE_SECONDAIRE, 'order' => 30, 'has_options' => true],
            ['name' => '2e secondaire', 'abbreviation' => '10S', 'cycle' => Level::CYCLE_SECONDAIRE, 'order' => 31, 'has_options' => true],
            ['name' => '3e secondaire', 'abbreviation' => '11S', 'cycle' => Level::CYCLE_SECONDAIRE, 'order' => 32, 'has_options' => true],
            ['name' => '4e secondaire', 'abbreviation' => '12S', 'cycle' => Level::CYCLE_SECONDAIRE, 'order' => 33, 'has_options' => true],
        ];
    }

    private function optionRows(): array
    {
        return SchoolOptionCatalog::rows();
    }
}
