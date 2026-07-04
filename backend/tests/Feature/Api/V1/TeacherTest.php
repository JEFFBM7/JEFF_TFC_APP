<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_admin_can_create_teacher_with_speciality_only(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_SECONDAIRE,
                'name' => 'MUTOMBO Jean',
                'speciality' => 'Mathématiques',
            ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_type', Teacher::TYPE_SECONDAIRE)
            ->assertJsonPath('data.speciality', 'Mathématiques')
            ->assertJsonPath('data.assigned_courses_count', 0);

        $this->assertDatabaseMissing('teacher_assignments', [
            'teacher_id' => Teacher::query()->value('id'),
        ]);
    }

    public function test_secondary_teacher_requires_speciality_on_create(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_SECONDAIRE,
                'name' => 'Sans spécialité',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['speciality']);
    }

    public function test_primary_teacher_has_no_speciality(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_PRIMAIRE,
                'name' => 'Instituteur Kabila',
            ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_type', Teacher::TYPE_PRIMAIRE)
            ->assertJsonPath('data.speciality', null);
    }

    public function test_primary_teacher_rejects_speciality_field(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_PRIMAIRE,
                'name' => 'Instituteur Test',
                'speciality' => 'Mathématiques',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['speciality']);
    }

    public function test_create_rejects_assignment_fields(): void
    {
        SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_SECONDAIRE,
                'name' => 'Test',
                'speciality' => 'Français',
                'classroom_id' => $classroom->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classroom_id']);
    }

    public function test_admin_can_create_teacher_without_email_and_password(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_PRIMAIRE,
                'name' => 'Marie Kabongo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', null);

        $teacher = Teacher::query()->find($response->json('data.id'));
        $this->assertNotNull($teacher?->user);
        $this->assertNotNull($teacher->registration_number);
        $this->assertTrue(Hash::check('Malunga2026', $teacher->user->password));
    }

    public function test_admin_can_update_teacher_profile(): void
    {
        $teacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_SECONDAIRE,
            'registration_number' => 'ENS-OLD-001',
            'speciality' => 'Histoire',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/teachers/{$teacher->id}", [
                'name' => 'Nom mis à jour',
                'email' => 'updated@malunga.test',
                'speciality' => 'Géographie',
                'grade' => 'Instituteur',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Nom mis à jour')
            ->assertJsonPath('data.registration_number', 'ENS-OLD-001')
            ->assertJsonPath('data.speciality', 'Géographie')
            ->assertJsonPath('data.grade', 'Instituteur');
    }

    public function test_update_rejects_manual_registration_number_change(): void
    {
        $teacher = Teacher::factory()->create([
            'registration_number' => 'ENS-OLD-001',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/teachers/{$teacher->id}", [
                'name' => 'Nom mis à jour',
                'registration_number' => 'ENS-NEW-001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_number']);
    }

    public function test_teachers_without_assignments_are_listed(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $assigned = Teacher::factory()->create(['speciality' => 'Mathématiques']);
        $unassigned = Teacher::factory()->create(['speciality' => 'Français']);

        $classroom = ClassRoom::factory()->create();
        $subject = Subject::factory()->create(['name' => 'Mathématiques']);

        $assigned->assignments()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
            'is_main' => false,
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/teachers')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($assigned->id, $ids);
        $this->assertContains($unassigned->id, $ids);
    }

    public function test_filter_teachers_by_subject_speciality(): void
    {
        Teacher::factory()->create(['speciality' => 'Mathématiques']);
        Teacher::factory()->create(['speciality' => 'Français']);
        $subject = Subject::factory()->create(['name' => 'Mathématiques']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/teachers?for_subject_id='.$subject->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Mathématiques', $res->json('data.0.speciality'));
    }

    public function test_filter_teachers_by_assignment_cycle(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);
        $primaryTeacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_PRIMAIRE]);
        $secondaryTeacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_SECONDAIRE]);
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

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($primaryTeacher->id, $ids);
        $this->assertNotContains($secondaryTeacher->id, $ids);
    }

    public function test_filter_teachers_by_classroom_without_subject_matches_cycle_type(): void
    {
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        $primaryTeacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_PRIMAIRE]);
        $secondaryTeacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_SECONDAIRE]);

        // Aucune affectation existante pour ce prof secondaire : le filtre par
        // affectation-cycle exacte (paramètre `cycle`) le raterait, mais le
        // filtre par classe doit tout de même le retenir puisque son type correspond.
        $this->assertSame(0, $secondaryTeacher->assignments()->count());

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/teachers?for_classroom_id='.$secondaryClassroom->id)
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($secondaryTeacher->id, $ids);
        $this->assertNotContains($primaryTeacher->id, $ids);
    }
}
