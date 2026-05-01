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
use Tests\TestCase;

class SubjectTeacherTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function makeClassroom(): ClassRoom
    {
        $level = Level::factory()->create();

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

    // ─── Teachers & Assignments ───────────────────────────────────────────────

    public function test_cannot_create_teacher_with_non_enseignant_user(): void
    {
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', ['user_id' => $parent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_admin_can_create_teacher(): void
    {
        $user = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/teachers', [
                'user_id' => $user->id,
                'speciality' => 'Mathématiques',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', $user->email);
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
}
