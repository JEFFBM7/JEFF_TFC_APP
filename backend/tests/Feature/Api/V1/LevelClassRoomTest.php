<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
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
            ->postJson('/api/v1/levels', ['name' => '6ème', 'order' => 1])
            ->assertCreated()
            ->assertJsonPath('data.name', '6ème');
    }

    public function test_level_name_unique(): void
    {
        Level::factory()->create(['name' => '6ème']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/levels', ['name' => '6ème'])
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

    // ─── ClassRooms ────────────────────────────────────────────────────────────

    public function test_admin_can_create_classroom(): void
    {
        $level = Level::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $level->id,
                'section' => 'A',
                'capacity' => 35,
            ])
            ->assertCreated()
            ->assertJsonPath('data.section', 'A')
            ->assertJsonPath('data.full_name', $level->name . ' A');
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
        $l1 = Level::factory()->create(['name' => '6ème']);
        $l2 = Level::factory()->create(['name' => '5ème']);
        ClassRoom::factory()->create(['level_id' => $l1->id, 'section' => 'A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/classrooms', ['level_id' => $l2->id, 'section' => 'A'])
            ->assertCreated();
    }

    public function test_filter_classrooms_by_level(): void
    {
        $l1 = Level::factory()->create();
        $l2 = Level::factory()->create();
        ClassRoom::factory()->create(['level_id' => $l1->id, 'section' => 'A']);
        ClassRoom::factory()->create(['level_id' => $l2->id, 'section' => 'B']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/classrooms?level_id=' . $l1->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('A', $res->json('data.0.section'));
    }
}
