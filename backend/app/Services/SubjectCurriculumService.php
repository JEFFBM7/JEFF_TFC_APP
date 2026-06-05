<?php

namespace App\Services;

use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\User;
use App\Support\AdminScopeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SubjectCurriculumService
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = config('curriculum_rdc', []);
    }

    /** @return Collection<int, Subject> */
    public function ensureCatalog(): Collection
    {
        $catalog = $this->config['catalog'] ?? [];
        $subjects = collect();

        foreach ($catalog as $name => $meta) {
            $subjects->push(Subject::query()->updateOrCreate(
                ['name' => $name],
                [
                    'code' => $meta['code'] ?? null,
                    'description' => $meta['description'] ?? null,
                    'default_coefficient' => $meta['default_coefficient'] ?? 1,
                    'status' => 'actif',
                ],
            ));
        }

        return $subjects->values();
    }

    /**
     * @return list<array{name: string, coefficient: float|int}>
     */
    public function resolveCurriculumForClassroom(ClassRoom $classroom): array
    {
        $classroom->loadMissing(['level', 'schoolOption']);

        $abbreviation = $classroom->level?->abbreviation;
        if ($abbreviation === null || $abbreviation === '') {
            return [];
        }

        if (in_array($abbreviation, $this->config['excluded_abbreviations'] ?? [], true)) {
            return [];
        }

        $groupKey = $this->config['level_groups'][$abbreviation] ?? null;
        if ($groupKey === null) {
            return [];
        }

        if ($groupKey === 'secondaire') {
            return $this->resolveSecondaryCurriculum($classroom, $abbreviation);
        }

        $entries = $this->config['groups'][$groupKey] ?? [];

        return $this->normalizeEntries($entries);
    }

    /**
     * @return array{
     *     classrooms_processed: int,
     *     subjects_in_catalog: int,
     *     subjects_created: int,
     *     links_created: int,
     *     links_updated: int
     * }
     */
    public function generateForSchoolYear(SchoolYear $schoolYear, ?User $actor = null): array
    {
        app(SchoolClassGenerationService::class)->ensureFixedStructure();

        $existingNames = Subject::query()->pluck('name')->all();
        $catalog = $this->ensureCatalog();
        $subjectsCreated = $catalog
            ->filter(fn (Subject $subject) => ! in_array($subject->name, $existingNames, true))
            ->count();

        $classrooms = ClassRoom::query()
            ->whereHas('schoolClass', fn ($query) => $query->where('school_year_id', $schoolYear->id))
            ->with(['level', 'schoolOption', 'subjects'])
            ->get();

        $classrooms = AdminScopeContext::applyClassroomScope(
            ClassRoom::query()->whereIn('id', $classrooms->pluck('id')),
            $actor,
        )->with(['level', 'schoolOption', 'subjects'])->get();

        if ($classrooms->isEmpty()) {
            return [
                'classrooms_processed' => 0,
                'subjects_in_catalog' => $catalog->count(),
                'subjects_created' => $subjectsCreated,
                'links_created' => 0,
                'links_updated' => 0,
            ];
        }

        $linksCreated = 0;
        $linksUpdated = 0;

        $assignmentSync = app(TeacherAssignmentSyncService::class);

        DB::transaction(function () use ($classrooms, $schoolYear, $assignmentSync, &$linksCreated, &$linksUpdated): void {
            foreach ($classrooms as $classroom) {
                [$created, $updated] = $this->attachCurriculumToClassroom($classroom);
                $linksCreated += $created;
                $linksUpdated += $updated;
                $classroom->unsetRelation('subjects');
                $classroom->load('subjects');
                $assignmentSync->refreshClassroomTitularSubjects($classroom->id, $schoolYear->id);
            }
        });

        return [
            'classrooms_processed' => $classrooms->count(),
            'subjects_in_catalog' => $catalog->count(),
            'subjects_created' => $subjectsCreated,
            'links_created' => $linksCreated,
            'links_updated' => $linksUpdated,
        ];
    }

    /**
     * @return array{0: int, 1: int} links created, links updated
     */
    private function attachCurriculumToClassroom(ClassRoom $classroom): array
    {
        $entries = $this->resolveCurriculumForClassroom($classroom);
        if ($entries === []) {
            return [0, 0];
        }

        $classroom->loadMissing('level');
        $abbreviation = $classroom->level?->abbreviation;
        $groupKey = $abbreviation !== null && $abbreviation !== ''
            ? ($this->config['level_groups'][$abbreviation] ?? null)
            : null;
        $syncCtebSubjects = $groupKey === 'cteb';

        $subjectsByName = Subject::query()
            ->whereIn('name', array_column($entries, 'name'))
            ->get()
            ->keyBy('name');

        if ($syncCtebSubjects) {
            $syncPayload = [];
            foreach ($entries as $entry) {
                $subject = $subjectsByName->get($entry['name']);
                if ($subject === null) {
                    continue;
                }

                $syncPayload[$subject->id] = ['coefficient' => (float) $entry['coefficient']];
            }

            if ($syncPayload === []) {
                return [0, 0];
            }

            $existingIds = $classroom->subjects()->pluck('subjects.id')->map(fn ($id) => (int) $id)->all();
            $classroom->subjects()->sync($syncPayload);
            $newIds = array_map('intval', array_keys($syncPayload));

            $created = count(array_diff($newIds, $existingIds));
            $updated = count(array_intersect($newIds, $existingIds));

            return [$created, $updated];
        }

        $created = 0;
        $updated = 0;

        foreach ($entries as $entry) {
            $subject = $subjectsByName->get($entry['name']);
            if ($subject === null) {
                continue;
            }

            $coefficient = (float) $entry['coefficient'];
            $existingCoefficient = $classroom->subjects()
                ->where('subjects.id', $subject->id)
                ->value('classroom_subject.coefficient');

            if ($existingCoefficient === null) {
                $classroom->subjects()->attach($subject->id, ['coefficient' => $coefficient]);
                $created++;
            } elseif ((float) $existingCoefficient !== $coefficient) {
                $classroom->subjects()->updateExistingPivot($subject->id, ['coefficient' => $coefficient]);
                $updated++;
            }
        }

        return [$created, $updated];
    }

    /**
     * @return list<array{name: string, coefficient: float|int}>
     */
    private function resolveSecondaryCurriculum(ClassRoom $classroom, string $abbreviation): array
    {
        $entries = $this->config['groups']['secondaire_common'] ?? [];

        if (in_array($abbreviation, $this->config['philosophy_abbreviations'] ?? [], true)) {
            $entries = array_merge($entries, $this->config['groups']['philosophy'] ?? []);
        }

        $optionName = $classroom->schoolOption?->name ?? $classroom->option;
        if (filled($optionName)) {
            $extensions = $this->config['option_extensions'][$optionName] ?? [];
            $entries = array_merge($entries, $extensions);
        }

        return $this->normalizeEntries($entries);
    }

    /**
     * @param  list<array{name: string, coefficient?: float|int}>  $entries
     * @return list<array{name: string, coefficient: float|int}>
     */
    private function normalizeEntries(array $entries): array
    {
        $normalized = [];
        $seen = [];

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            if (! is_string($name) || $name === '' || isset($seen[$name])) {
                continue;
            }

            $catalogMeta = $this->config['catalog'][$name] ?? [];
            $coefficient = $entry['coefficient']
                ?? $catalogMeta['default_coefficient']
                ?? 1;

            $normalized[] = [
                'name' => $name,
                'coefficient' => $coefficient,
            ];
            $seen[$name] = true;
        }

        return $normalized;
    }
}
