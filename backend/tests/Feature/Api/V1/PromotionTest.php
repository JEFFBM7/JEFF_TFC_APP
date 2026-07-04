<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function division(SchoolYear $year, Level $level, string $section = 'A', int $capacity = 40): ClassRoom
    {
        $schoolClass = SchoolClass::factory()->create([
            'school_year_id' => $year->id,
            'level_id' => $level->id,
            'school_option_id' => null,
        ]);

        return ClassRoom::factory()->create([
            'school_class_id' => $schoolClass->id,
            'level_id' => $level->id,
            'section' => $section,
            'capacity' => $capacity,
            'active' => true,
        ]);
    }

    private function gradeStudent(Student $student, ClassRoom $classroom, Term $term, float $value): void
    {
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => Subject::factory()->create()->id,
            'term_id' => $term->id,
            'max_value' => 20,
        ]);

        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => $value,
            'absent' => false,
        ]);
    }

    /**
     * Scénario commun : niveau de départ (order 10), niveau suivant (order 11),
     * une année source et une année cible (avec les classes générées), un élève
     * admis (15/20) et un élève en échec (6/20).
     *
     * @return array<string, mixed>
     */
    private function scaffold(): array
    {
        $levelFrom = Level::factory()->create(['order' => 10, 'has_options' => false]);
        $levelNext = Level::factory()->create(['order' => 11, 'has_options' => false]);

        $from = SchoolYear::factory()->create(['name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']);
        $to = SchoolYear::factory()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);

        $divFrom = $this->division($from, $levelFrom);
        $divNext = $this->division($to, $levelNext);   // cible des admis
        $divRepeat = $this->division($to, $levelFrom);  // cible des redoublants

        $term = Term::factory()->create(['school_year_id' => $from->id, 'position' => 1]);

        $admitted = Student::factory()->create([
            'classroom_id' => $divFrom->id,
            'enrollment_school_year_id' => $from->id,
            'last_name' => 'Admis',
        ]);
        $failing = Student::factory()->create([
            'classroom_id' => $divFrom->id,
            'enrollment_school_year_id' => $from->id,
            'last_name' => 'Echec',
        ]);

        $this->gradeStudent($admitted, $divFrom, $term, 15);
        $this->gradeStudent($failing, $divFrom, $term, 6);

        return compact('from', 'to', 'divFrom', 'divNext', 'divRepeat', 'admitted', 'failing');
    }

    /** @return array<int, array<string, mixed>> */
    private function decisionsFromPreview(array $students): array
    {
        return collect($students)
            ->map(fn (array $row) => [
                'enrollment_id' => $row['enrollment_id'],
                'decision' => $row['suggested_decision'] ?? 'skip',
                'target_classroom_id' => $row['target_classroom_id'],
            ])
            ->all();
    }

    public function test_preview_suggests_promotion_and_repetition(): void
    {
        $s = $this->scaffold();

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}");

        $res->assertOk();
        $data = $res->json('data');

        $this->assertEquals(10.0, $data['threshold']);
        $this->assertSame(1, $data['summary']['promote']);
        $this->assertSame(1, $data['summary']['repeat']);

        $rows = collect($data['students'])->keyBy(fn ($r) => $r['student']['id']);
        $this->assertSame('promu', $rows[$s['admitted']->id]['suggested_decision']);
        $this->assertSame($s['divNext']->id, $rows[$s['admitted']->id]['target_classroom_id']);
        $this->assertSame('redouble', $rows[$s['failing']->id]['suggested_decision']);
        $this->assertSame($s['divRepeat']->id, $rows[$s['failing']->id]['target_classroom_id']);
    }

    public function test_commit_creates_target_enrollments_and_marks_sources(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');

        $res = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/v1/school-years/{$s['from']->id}/promotion/commit",
            ['to_year_id' => $s['to']->id, 'decisions' => $this->decisionsFromPreview($preview)],
        );

        $res->assertCreated();
        $this->assertSame(1, $res->json('data.promoted_count'));
        $this->assertSame(1, $res->json('data.repeated_count'));

        // Inscriptions créées dans l'année cible, avec la bonne classe.
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s['admitted']->id,
            'school_year_id' => $s['to']->id,
            'classroom_id' => $s['divNext']->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s['failing']->id,
            'school_year_id' => $s['to']->id,
            'classroom_id' => $s['divRepeat']->id,
        ]);

        // Statuts des inscriptions sources.
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s['admitted']->id,
            'school_year_id' => $s['from']->id,
            'status' => Enrollment::STATUS_PROMOTED,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s['failing']->id,
            'school_year_id' => $s['from']->id,
            'status' => Enrollment::STATUS_REPEATING,
        ]);
    }

    public function test_commit_is_idempotent(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');
        $decisions = $this->decisionsFromPreview($preview);

        foreach ([1, 2] as $_) {
            $this->actingAs($admin, 'sanctum')->postJson(
                "/api/v1/school-years/{$s['from']->id}/promotion/commit",
                ['to_year_id' => $s['to']->id, 'decisions' => $decisions],
            )->assertCreated();
        }

        // Une seule inscription par élève et par année malgré les deux passages.
        $this->assertSame(1, Enrollment::query()
            ->where('student_id', $s['admitted']->id)
            ->where('school_year_id', $s['to']->id)
            ->count());
    }

    public function test_history_preserved_after_promotion_and_year_switch(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/v1/school-years/{$s['from']->id}/promotion/commit",
            ['to_year_id' => $s['to']->id, 'decisions' => $this->decisionsFromPreview($preview)],
        )->assertCreated();

        // L'année cible devient courante → le cache des élèves bascule.
        $s['to']->update(['is_current' => true]);

        $admitted = $s['admitted']->fresh();
        $this->assertSame($s['divNext']->id, $admitted->classroom_id, 'le cache doit pointer vers la nouvelle classe');
        $this->assertSame($s['to']->id, $admitted->enrollment_school_year_id);

        // Pourtant, le détail de l'ANCIENNE année liste encore l'élève dans son ancienne classe.
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/classrooms/{$s['divFrom']->id}/details");

        $res->assertOk();
        $ids = collect($res->json('data.students'))->pluck('id');
        $this->assertTrue($ids->contains($s['admitted']->id), 'le roster de l’année passée doit rester intact');
        $this->assertTrue($ids->contains($s['failing']->id));
    }

    public function test_rollback_removes_target_enrollments_and_resets_sources(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');

        $batchId = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/v1/school-years/{$s['from']->id}/promotion/commit",
            ['to_year_id' => $s['to']->id, 'decisions' => $this->decisionsFromPreview($preview)],
        )->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/promotion-batches/{$batchId}/rollback")
            ->assertOk();

        // Plus aucune inscription dans l'année cible.
        $this->assertSame(0, Enrollment::query()->where('school_year_id', $s['to']->id)->count());

        // Les inscriptions sources sont redevenues actives.
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s['admitted']->id,
            'school_year_id' => $s['from']->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);
    }

    public function test_rollback_blocked_once_target_year_is_current(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');

        $batchId = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/v1/school-years/{$s['from']->id}/promotion/commit",
            ['to_year_id' => $s['to']->id, 'decisions' => $this->decisionsFromPreview($preview)],
        )->json('data.id');

        $s['to']->update(['is_current' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/promotion-batches/{$batchId}/rollback")
            ->assertStatus(409);
    }

    public function test_terminal_level_student_graduates(): void
    {
        $admin = $this->admin();
        $topLevel = Level::factory()->create(['order' => 99, 'has_options' => false]);

        $from = SchoolYear::factory()->create(['starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']);
        $to = SchoolYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $divTop = $this->division($from, $topLevel);
        $term = Term::factory()->create(['school_year_id' => $from->id, 'position' => 1]);

        $student = Student::factory()->create([
            'classroom_id' => $divTop->id,
            'enrollment_school_year_id' => $from->id,
        ]);
        $this->gradeStudent($student, $divTop, $term, 16);

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$from->id}/promotion/preview?to_year_id={$to->id}")
            ->json('data');

        $row = collect($preview['students'])->firstWhere('student.id', $student->id);
        $this->assertSame('graduate', $row['resolution_status']);
        $this->assertSame(1, $preview['summary']['graduate']);

        $sourceEnrollmentId = $row['enrollment_id'];
        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/v1/school-years/{$from->id}/promotion/commit",
            ['to_year_id' => $to->id, 'decisions' => [[
                'enrollment_id' => $sourceEnrollmentId,
                'decision' => 'diplome',
                'target_classroom_id' => null,
            ]]],
        )->assertCreated();

        $this->assertDatabaseHas('enrollments', [
            'id' => $sourceEnrollmentId,
            'status' => Enrollment::STATUS_GRADUATED,
        ]);
        $this->assertSame(0, Enrollment::query()->where('school_year_id', $to->id)->count());
    }

    public function test_commit_into_already_current_year_syncs_student_pointers(): void
    {
        $s = $this->scaffold();
        $admin = $this->admin();

        // L'année cible est activée AVANT le passage (cas « Rendre courante & terminer »).
        $s['to']->update(['is_current' => true]);

        $preview = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/school-years/{$s['from']->id}/promotion/preview?to_year_id={$s['to']->id}")
            ->json('data.students');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/school-years/{$s['from']->id}/promotion/commit", [
                'to_year_id' => $s['to']->id,
                'decisions' => $this->decisionsFromPreview($preview),
            ])
            ->assertCreated()
            ->assertJsonPath('data.promoted_count', 1);

        // Le cache de l'élève admis pointe immédiatement sur la nouvelle année.
        $admitted = $s['admitted']->fresh();
        $this->assertSame($s['to']->id, (int) $admitted->enrollment_school_year_id);
        $this->assertSame($s['divNext']->id, (int) $admitted->classroom_id);
    }

    public function test_preview_creates_missing_target_classes_automatically(): void
    {
        $levelFrom = Level::factory()->create(['order' => 10, 'has_options' => false]);
        $levelNext = Level::factory()->create(['order' => 11, 'has_options' => false]);

        $from = SchoolYear::factory()->create(['starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']);
        $to = SchoolYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);

        $divFrom = $this->division($from, $levelFrom);
        $term = Term::factory()->create(['school_year_id' => $from->id, 'position' => 1]);

        $student = Student::factory()->create([
            'classroom_id' => $divFrom->id,
            'enrollment_school_year_id' => $from->id,
        ]);
        $this->gradeStudent($student, $divFrom, $term, 15);

        // Année cible volontairement vide : aucune classe générée.
        $this->assertSame(0, SchoolClass::query()->where('school_year_id', $to->id)->count());

        $preview = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$from->id}/promotion/preview?to_year_id={$to->id}")
            ->assertOk()
            ->json('data.students');

        // Les classes cibles (niveau suivant + redoublement) ont été créées
        // avec une division, et l'élève admis est résolu automatiquement.
        $this->assertSame('ok', $preview[0]['resolution_status']);
        $this->assertNotNull($preview[0]['target_classroom_id']);
        $this->assertSame(2, SchoolClass::query()->where('school_year_id', $to->id)->count());
    }

    public function test_cteb_student_option_choice_resolves_secondary_target(): void
    {
        $levelCteb = Level::factory()->create(['order' => 21, 'has_options' => false, 'cycle' => Level::CYCLE_CTEB]);
        $levelSec = Level::factory()->create(['order' => 30, 'has_options' => true, 'cycle' => Level::CYCLE_SECONDAIRE]);
        $option = \App\Models\SchoolOption::query()->create([
            'name' => 'Scientifique',
            'abbreviation' => 'SCI',
            'cycle' => Level::CYCLE_SECONDAIRE,
            'filiere' => 'generale',
        ]);

        $from = SchoolYear::factory()->create(['starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']);
        $to = SchoolYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);

        $divCteb = $this->division($from, $levelCteb);
        $term = Term::factory()->create(['school_year_id' => $from->id, 'position' => 1]);

        $withChoice = Student::factory()->create([
            'classroom_id' => $divCteb->id,
            'enrollment_school_year_id' => $from->id,
            'last_name' => 'AvecChoix',
        ]);
        $withoutChoice = Student::factory()->create([
            'classroom_id' => $divCteb->id,
            'enrollment_school_year_id' => $from->id,
            'last_name' => 'SansChoix',
        ]);
        $this->gradeStudent($withChoice, $divCteb, $term, 14);
        $this->gradeStudent($withoutChoice, $divCteb, $term, 13);

        \App\Models\StudentOptionChoice::query()->create([
            'student_id' => $withChoice->id,
            'school_year_id' => $from->id,
            'school_option_id' => $option->id,
            'submitted_at' => now(),
        ]);

        $preview = collect($this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$from->id}/promotion/preview?to_year_id={$to->id}")
            ->assertOk()
            ->json('data.students'));

        $rowWith = $preview->firstWhere('student.id', $withChoice->id);
        $rowWithout = $preview->firstWhere('student.id', $withoutChoice->id);

        // Le choix déposé résout la classe cible (créée à la volée avec l'option).
        $this->assertSame('ok', $rowWith['resolution_status']);
        $this->assertNotNull($rowWith['target_classroom_id']);
        $this->assertTrue(SchoolClass::query()
            ->where('school_year_id', $to->id)
            ->where('level_id', $levelSec->id)
            ->where('school_option_id', $option->id)
            ->exists());

        // Sans choix déposé : reste à arbitrer manuellement.
        $this->assertSame('needs_option', $rowWithout['resolution_status']);
    }
}
