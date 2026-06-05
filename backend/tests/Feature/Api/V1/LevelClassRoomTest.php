<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelClassRoomTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    // ─── Levels ────────────────────────────────────────────────────────────────

    public function test_non_admin_cannot_list_levels(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Parent]), 'sanctum')
            ->getJson('/api/v1/levels')
            ->assertForbidden();
    }

    public function test_admin_can_create_level(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/levels', [
                'name' => '1ère primaire',
                'cycle' => Level::CYCLE_PRIMAIRE,
                'order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '1ère primaire')
            ->assertJsonPath('data.cycle', Level::CYCLE_PRIMAIRE);
    }

    public function test_level_cycle_must_be_valid(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/levels', [
                'name' => 'Cycle inconnu',
                'cycle' => 'universite',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cycle']);
    }

    public function test_level_name_unique(): void
    {
        Level::factory()->create(['name' => '1ère primaire']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/levels', [
                'name' => '1ère primaire',
                'cycle' => Level::CYCLE_PRIMAIRE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_cannot_delete_level_with_classrooms(): void
    {
        $level = Level::factory()->create();
        ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/levels/{$level->id}")
            ->assertUnprocessable();
    }

    public function test_admin_can_delete_empty_level(): void
    {
        $level = Level::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/levels/{$level->id}")
            ->assertNoContent();
    }

    public function test_admin_can_create_school_option(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-options', ['name' => 'Mécanique'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mécanique');
    }

    // ─── ClassRooms ────────────────────────────────────────────────────────────

    public function test_admin_can_create_classroom(): void
    {
        $level = Level::factory()->create(['name' => '1ère primaire', 'cycle' => Level::CYCLE_PRIMAIRE]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.section', 'A')
            ->assertJsonPath('data.option', '')
            ->assertJsonPath('data.full_name', $level->name.' A')
            ->assertJsonPath('data.student_count', 0);
    }

    public function test_secondary_classroom_requires_option(): void
    {
        $level = Level::factory()->create(['name' => '1ère secondaire', 'cycle' => Level::CYCLE_SECONDAIRE]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_option_id']);
    }

    public function test_admin_can_create_secondary_classroom_with_option(): void
    {
        $level = Level::factory()->create(['name' => '1ère secondaire', 'cycle' => Level::CYCLE_SECONDAIRE]);
        $option = SchoolOption::query()->create(['name' => 'Mécanique']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
                'school_option_id' => $option->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.option', 'Mécanique')
            ->assertJsonPath('data.school_option_id', $option->id)
            ->assertJsonPath('data.full_name', '1ère secondaire Mécanique A');
    }

    public function test_non_secondary_classroom_ignores_option(): void
    {
        $level = Level::factory()->create(['name' => '2e primaire', 'cycle' => Level::CYCLE_PRIMAIRE]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
                'option' => 'Mécanique',
            ])
            ->assertCreated()
            ->assertJsonPath('data.option', '')
            ->assertJsonPath('data.full_name', '2e primaire A');
    }

    public function test_section_unique_per_level(): void
    {
        $level = Level::factory()->create();
        ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section']);
    }

    public function test_same_section_allowed_in_different_levels(): void
    {
        $l1 = Level::factory()->create(['name' => '1ère primaire', 'cycle' => Level::CYCLE_PRIMAIRE]);
        $l2 = Level::factory()->create(['name' => '2e primaire', 'cycle' => Level::CYCLE_PRIMAIRE]);
        ClassRoom::factory()->create(['level_id' => $l1->id, 'section' => 'A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', ['level_id' => $l2->id, 'section' => 'A'])
            ->assertCreated();
    }

    public function test_same_section_allowed_for_different_secondary_options(): void
    {
        $level = Level::factory()->create(['name' => '1ère secondaire', 'cycle' => Level::CYCLE_SECONDAIRE]);
        $mecanique = SchoolOption::query()->create(['name' => 'Mécanique']);
        $electricite = SchoolOption::query()->create(['name' => 'Électricité']);
        ClassRoom::factory()->create([
            'level_id' => $level->id,
            'section' => 'A',
            'option' => $mecanique->name,
            'school_option_id' => $mecanique->id,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
                'school_option_id' => $electricite->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', '1ère secondaire Électricité A');
    }

    public function test_filter_classrooms_by_level(): void
    {
        $l1 = Level::factory()->create();
        $l2 = Level::factory()->create();
        ClassRoom::factory()->create(['level_id' => $l1->id, 'section' => 'A']);
        ClassRoom::factory()->create(['level_id' => $l2->id, 'section' => 'B']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/classrooms?level_id='.$l1->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('A', $res->json('data.0.section'));
        $this->assertArrayHasKey('student_count', $res->json('data.0'));
        $this->assertArrayHasKey('main_teacher', $res->json('data.0'));
        $this->assertArrayHasKey('grade_average', $res->json('data.0'));
    }

    public function test_classroom_summaries_respect_current_school_year(): void
    {
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2026-2027']);
        $oldYear = SchoolYear::factory()->create(['name' => '2025-2026']);
        $classroom = ClassRoom::factory()->create(['section' => 'A']);
        $currentTeacher = Teacher::factory()->create();
        $oldTeacher = Teacher::factory()->create();

        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $currentYear->id,
        ]);
        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $oldYear->id,
        ]);

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
            ->getJson('/api/v1/classrooms')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('data.0.student_count'));
        $this->assertSame($currentTeacher->id, $res->json('data.0.main_teacher.id'));
        $this->assertSame($currentYear->id, $res->json('data.0.current_school_year_id'));
    }
}
