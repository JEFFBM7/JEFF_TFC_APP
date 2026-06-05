<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTeacherTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function makeClassroom(string $cycle = Level::CYCLE_PRIMAIRE): ClassRoom
    {
        $level = Level::factory()->create(['cycle' => $cycle]);

        return ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
    }

    // ─── Subjects ────────────────────────────────────────────────────────────

    public function test_admin_can_create_subject(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/subjects', ['name' => 'Mathématiques'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mathématiques');
    }

    public function test_admin_can_create_full_course_dossier(): void
    {
        $classroom = $this->makeClassroom(Level::CYCLE_SECONDAIRE);
        $teacher = Teacher::factory()->create(['speciality' => 'Mathématiques']);
        $year = SchoolYear::factory()->create();
        $term = $year->terms()->create([
            'name' => '1er trimestre',
            'position' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-20',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/subjects', [
                'name' => 'Mathématiques',
                'code' => 'MATH-001',
                'description' => 'Algèbre et géométrie.',
                'default_coefficient' => 4,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
                'term_id' => $term->id,
                'teacher_id' => $teacher->id,
                'weekly_hours' => 5,
                'evaluation_type' => 'sur_20',
                'status' => 'actif',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mathématiques')
            ->assertJsonPath('data.code', 'MATH-001')
            ->assertJsonPath('data.classroom_id', $classroom->id)
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.term_id', $term->id);

        $subjectId = $response->json('data.id');

        $this->assertDatabaseHas('classroom_subject', [
            'classroom_id' => $classroom->id,
            'subject_id' => $subjectId,
            'coefficient' => 4,
        ]);
        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subjectId,
            'school_year_id' => $year->id,
            'term_id' => $term->id,
            'weekly_hours' => 5,
        ]);
    }

    public function test_subject_name_unique(): void
    {
        Subject::factory()->create(['name' => 'Maths']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/subjects', ['name' => 'Maths'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_attach_subject_with_coefficient(): void
    {
        $classroom = $this->makeClassroom();
        $subject = Subject::factory()->create(['name' => 'Physique']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/classrooms/{$classroom->id}/subjects", [
                'subject_id' => $subject->id,
                'coefficient' => 3.0,
            ])
            ->assertOk();

        $this->assertDatabaseHas('classroom_subject', [
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'coefficient' => 3.0,
        ]);
    }

    public function test_can_detach_subject_from_classroom(): void
    {
        $classroom = $this->makeClassroom();
        $subject = Subject::factory()->create(['name' => 'Chimie']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 2.0]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/classrooms/{$classroom->id}/subjects/{$subject->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('classroom_subject', [
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);
    }

    public function test_filter_subjects_by_classroom_cycle(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);
        $primarySubject = Subject::factory()->create(['name' => 'Primaire Test']);
        $secondarySubject = Subject::factory()->create(['name' => 'Secondaire Test']);

        $primaryClassroom->subjects()->attach($primarySubject->id, ['coefficient' => 1.0]);
        $secondaryClassroom->subjects()->attach($secondarySubject->id, ['coefficient' => 1.0]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/subjects?cycle='.Level::CYCLE_PRIMAIRE)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($primarySubject->id, $res->json('data.0.id'));
    }

    public function test_all_course_filter_respects_current_school_year(): void
    {
        $classroom = $this->makeClassroom();
        $teacher = Teacher::factory()->create();
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2026-2027']);
        $oldYear = SchoolYear::factory()->create(['name' => '2025-2026']);
        $currentSubject = Subject::factory()->create(['name' => 'Cours courant']);
        $oldSubject = Subject::factory()->create(['name' => 'Cours ancien']);
        $unassignedSubject = Subject::factory()->create(['name' => 'Cours sans année']);

        $currentSubject->classrooms()->attach($classroom->id, ['coefficient' => 1]);
        $oldSubject->classrooms()->attach($classroom->id, ['coefficient' => 1]);
        $unassignedSubject->classrooms()->attach($classroom->id, ['coefficient' => 1]);

        $currentSubject->assignments()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $currentYear->id,
        ]);
        $oldSubject->assignments()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $oldYear->id,
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/subjects')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->unique()->values()->all();

        $this->assertEqualsCanonicalizing(
            [$currentSubject->id, $oldSubject->id, $unassignedSubject->id],
            $ids,
        );
    }

    // ─── Teachers & Assignments ───────────────────────────────────────────────

    public function test_admin_can_create_teacher_with_speciality(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_SECONDAIRE,
                'name' => 'Prof Test',
                'speciality' => 'Mathématiques',
            ])
            ->assertCreated()
            ->assertJsonPath('data.speciality', 'Mathématiques');
    }

    public function test_admin_can_assign_teacher_to_course_from_subjects_endpoint(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $classroom = $this->makeClassroom(Level::CYCLE_SECONDAIRE);
        $subject = Subject::factory()->create(['name' => 'Mathématiques']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 4]);
        $teacher = Teacher::factory()->create(['speciality' => 'Mathématiques']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/subjects/{$subject->id}/assign-teacher", [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $teacher->id);

        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);
    }

    public function test_primary_course_assign_is_rejected_use_classroom_endpoint(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $subject = Subject::factory()->create(['name' => 'Calcul']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 2]);
        $teacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_PRIMAIRE,
            'speciality' => null,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/subjects/{$subject->id}/assign-teacher", [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classroom_id']);
    }

    public function test_refresh_titular_subjects_after_curriculum_generated_late(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $teacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_PRIMAIRE,
            'speciality' => null,
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $year->id,
            'subject_id' => null,
            'is_main' => true,
        ]);

        $math = Subject::factory()->create(['name' => 'Calcul']);
        $classroom->subjects()->attach($math->id, ['coefficient' => 2]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/subjects?classroom_id='.$classroom->id)
            ->assertOk()
            ->assertJsonPath('data.0.teacher_id', $teacher->id);

        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $math->id,
            'school_year_id' => $year->id,
        ]);
    }

    public function test_primary_teacher_assign_classroom_syncs_all_subjects(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $math = Subject::factory()->create(['name' => 'Calcul']);
        $french = Subject::factory()->create(['name' => 'Français']);
        $classroom->subjects()->attach($math->id, ['coefficient' => 2]);
        $classroom->subjects()->attach($french->id, ['coefficient' => 2]);
        $teacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_PRIMAIRE,
            'speciality' => null,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/teachers/{$teacher->id}/assign-classroom", [
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.main_classroom.id', $classroom->id);

        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => null,
            'school_year_id' => $year->id,
            'is_main' => true,
        ]);
        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $math->id,
            'school_year_id' => $year->id,
        ]);
        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $french->id,
            'school_year_id' => $year->id,
        ]);
    }

    public function test_secondary_course_list_shows_subject_teacher_not_principal_fallback(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $classroom = $this->makeClassroom(Level::CYCLE_SECONDAIRE);
        $math = Subject::factory()->create(['name' => 'Mathématiques']);
        $french = Subject::factory()->create(['name' => 'Français']);
        $classroom->subjects()->attach($math->id, ['coefficient' => 4]);
        $classroom->subjects()->attach($french->id, ['coefficient' => 3]);

        $principal = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_SECONDAIRE]);
        $mathTeacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_SECONDAIRE,
            'speciality' => 'Mathématiques',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $principal->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $year->id,
            'subject_id' => null,
            'is_main' => true,
        ]);
        TeacherAssignment::query()->create([
            'teacher_id' => $mathTeacher->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $year->id,
            'subject_id' => $math->id,
            'is_main' => false,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/subjects?classroom_id='.$classroom->id)
            ->assertOk();

        $rows = collect($response->json('data'));
        $mathRow = $rows->firstWhere('id', $math->id);
        $frenchRow = $rows->firstWhere('id', $french->id);

        $this->assertSame($mathTeacher->id, $mathRow['teacher_id']);
        $this->assertNull($frenchRow['teacher_id']);
    }

    public function test_assign_teacher_rejects_mismatched_speciality(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $classroom = $this->makeClassroom(Level::CYCLE_SECONDAIRE);
        $subject = Subject::factory()->create(['name' => 'Mathématiques']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 4]);
        $teacher = Teacher::factory()->create(['speciality' => 'Français']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/subjects/{$subject->id}/assign-teacher", [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_filter_teachers_by_assignment_cycle(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);
        $primaryTeacher = Teacher::factory()->create();
        $secondaryTeacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create();

        $primaryTeacher->assignments()->create([
            'classroom_id' => $primaryClassroom->id,
            'school_year_id' => $year->id,
            'subject_id' => null,
            'is_main' => true,
        ]);
        $secondaryTeacher->assignments()->create([
            'classroom_id' => $secondaryClassroom->id,
            'school_year_id' => $year->id,
            'subject_id' => null,
            'is_main' => true,
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/teachers?cycle='.Level::CYCLE_PRIMAIRE)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($primaryTeacher->id, $res->json('data.0.id'));
    }

    public function test_all_teachers_are_listed_regardless_of_school_year_assignments(): void
    {
        $classroom = $this->makeClassroom();
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2026-2027']);
        $oldYear = SchoolYear::factory()->create(['name' => '2025-2026']);
        $currentTeacher = Teacher::factory()->create();
        $oldTeacher = Teacher::factory()->create();
        $unassignedTeacher = Teacher::factory()->create();

        $currentTeacher->assignments()->create([
            'classroom_id' => $classroom->id,
            'school_year_id' => $currentYear->id,
            'subject_id' => null,
            'is_main' => true,
        ]);
        $oldTeacher->assignments()->create([
            'classroom_id' => $classroom->id,
            'school_year_id' => $oldYear->id,
            'subject_id' => null,
            'is_main' => true,
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/teachers')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($currentTeacher->id, $ids);
        $this->assertContains($oldTeacher->id, $ids);
        $this->assertContains($unassignedTeacher->id, $ids);
    }

    public function test_admin_can_create_assignment(): void
    {
        $classroom = $this->makeClassroom();
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $teacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'school_year_id' => $year->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_id', $teacher->id);
    }

    public function test_regular_assignment_requires_subject(): void
    {
        $classroom = $this->makeClassroom();
        $teacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_duplicate_assignment_rejected(): void
    {
        $classroom = $this->makeClassroom();
        $subject = Subject::factory()->create(['name' => 'Géo']);
        $teacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create();

        $payload = [
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ];

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', $payload)
            ->assertCreated();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', $payload)
            ->assertUnprocessable();
    }

    public function test_main_assignment_can_be_changed(): void
    {
        $classroom = $this->makeClassroom();
        $year = SchoolYear::factory()->create();
        $firstTeacher = Teacher::factory()->create();
        $secondTeacher = Teacher::factory()->create();

        $assignment = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $firstTeacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
                'is_main' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject_id', null)
            ->assertJsonPath('data.is_main', true)
            ->json('data');

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/assignments/{$assignment['id']}", [
                'teacher_id' => $secondTeacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
                'is_main' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $secondTeacher->id)
            ->assertJsonPath('data.subject_id', null)
            ->assertJsonPath('data.is_main', true);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/assignments/{$assignment['id']}")
            ->assertNoContent();
    }

    public function test_only_one_main_assignment_per_classroom_year(): void
    {
        $classroom = $this->makeClassroom();
        $year = SchoolYear::factory()->create();
        $firstTeacher = Teacher::factory()->create();
        $secondTeacher = Teacher::factory()->create();

        $first = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $firstTeacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
                'is_main' => true,
            ])
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $secondTeacher->id,
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
                'is_main' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('teacher_assignments', ['id' => $first['id'], 'is_main' => false]);
        $this->assertDatabaseHas('teacher_assignments', ['id' => $second['id'], 'is_main' => true]);
    }
}
