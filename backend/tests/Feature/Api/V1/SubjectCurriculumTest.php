<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolClassGenerationService;
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectCurriculumTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function prepareYearWithDivisions(SchoolYear $year): void
    {
        app(SchoolClassGenerationService::class)->generateBaseClasses($year);

        $year->schoolClasses()
            ->get()
            ->each(function (SchoolClass $schoolClass): void {
                if ($schoolClass->divisions()->count() === 0) {
                    app(SchoolClassGenerationService::class)->addDivisions($schoolClass, 1, 40);
                }
            });
    }

    private function classroomForLevel(SchoolYear $year, string $levelAbbreviation, ?string $optionName = null): ClassRoom
    {
        $query = ClassRoom::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('school_year_id', $year->id))
            ->whereHas('level', fn ($q) => $q->where('abbreviation', $levelAbbreviation));

        if ($optionName !== null) {
            $query->whereHas('schoolOption', fn ($q) => $q->where('name', $optionName));
        } else {
            $query->whereNull('school_option_id');
        }

        return $query->firstOrFail();
    }

    public function test_generates_primary_curriculum_for_5p(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated()
            ->assertJsonStructure(['data' => ['classrooms_processed', 'subjects_in_catalog', 'links_created']]);

        $classroom = $this->classroomForLevel($year, '5P');
        $subjectNames = $classroom->subjects()->pluck('name')->all();

        $this->assertContains('Français', $subjectNames);
        $this->assertContains('Mathématiques', $subjectNames);
        $this->assertContains('Éducation civique et morale', $subjectNames);

        $francais = $classroom->subjects()->where('name', 'Français')->first();
        $this->assertSame(4.0, (float) $francais->pivot->coefficient);
    }

    public function test_generates_cteb_curriculum_for_7eb(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated();

        $classroom = $this->classroomForLevel($year, '7EB');
        $subjectNames = $classroom->subjects()->pluck('name')->all();

        $expectedCtebSubjects = config('cteb_subjects.curriculum', []);
        $this->assertCount(count($expectedCtebSubjects), $subjectNames);

        foreach ($expectedCtebSubjects as $entry) {
            $this->assertContains($entry['name'], $subjectNames);
        }

        $this->assertNotContains('Mathématiques', $subjectNames);
        $this->assertNotContains('Biologie', $subjectNames);
        $this->assertContains('Arithmétique', $subjectNames);
        $this->assertContains('Sciences Physiques', $subjectNames);
        $this->assertContains("Techn. d'Info. & Com (TIC)", $subjectNames);
    }

    public function test_generates_technical_curriculum_for_mecanique_9s(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated();

        $classroom = $this->classroomForLevel($year, '9S', 'Mécanique');
        $subjectNames = $classroom->subjects()->pluck('name')->all();

        $this->assertContains('Français', $subjectNames);
        $this->assertContains('Atelier mécanique', $subjectNames);
        $this->assertContains('Mathématiques appliquées', $subjectNames);
    }

    public function test_generation_is_idempotent(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated();

        $pivotCountAfterFirst = DB::table('classroom_subject')->count();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated()
            ->assertJsonPath('data.links_created', 0);

        $this->assertSame($pivotCountAfterFirst, DB::table('classroom_subject')->count());
    }

    public function test_cycle_admin_primary_does_not_touch_secondary_divisions(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);

        $secondaryClassroom = $this->classroomForLevel($year, '9S', 'Mécanique');

        $manualSubject = Subject::factory()->create(['name' => 'Matière manuelle secondaire test']);
        $secondaryClassroom->subjects()->sync([
            $manualSubject->id => ['coefficient' => 2],
        ]);

        $primaryAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($primaryAdmin, 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertCreated();

        $secondaryClassroom->refresh();
        $this->assertSame(
            1,
            $secondaryClassroom->subjects()->count(),
            'La division secondaire ne doit pas recevoir le programme auto.',
        );
        $this->assertSame('Matière manuelle secondaire test', $secondaryClassroom->subjects()->first()->name);

        $primaryClassroom = $this->classroomForLevel($year, '5P');
        $this->assertGreaterThan(0, $primaryClassroom->subjects()->count());
    }

    public function test_year_without_divisions_returns_422(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Aucune division trouvée pour cette année. Générez d’abord les classes de base.',
            );
    }

    public function test_curriculum_links_are_listed_without_teacher_assignment(): void
    {
        $year = SchoolYear::factory()->current()->create(['name' => '2026-2027']);
        $this->prepareYearWithDivisions($year);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum");
        $response->assertCreated();
        $this->assertGreaterThan(0, $response->json('data.links_created'));

        $classroom = $this->classroomForLevel($year, '5P');
        $this->assertGreaterThan(0, $classroom->subjects()->count());

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/subjects')
            ->assertOk();

        $this->assertTrue(
            collect($response->json('data'))->contains(
                fn (array $row) => $row['name'] === 'Français'
                    && ($row['classroom']['level']['abbreviation'] ?? null) === '5P',
            ),
        );
    }

    public function test_archived_year_rejects_curriculum_generation(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-curriculum")
            ->assertStatus(423);
    }
}
