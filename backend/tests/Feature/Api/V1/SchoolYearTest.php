<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_parent_cannot_list_school_years(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/school-years')
            ->assertForbidden();
    }

    public function test_admin_can_create_school_year(): void
    {
        $payload = [
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ];

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', '2026-2027')
            ->assertJsonPath('data.is_current', true);

        $this->assertDatabaseHas('school_years', [
            'name' => '2026-2027',
            'is_current' => true,
        ]);
    }

    public function test_creating_a_current_year_uncurrents_others(): void
    {
        SchoolYear::factory()->current()->create(['name' => '2025-2026']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
            ])
            ->assertCreated();

        $this->assertSame(1, SchoolYear::query()->where('is_current', true)->count());
    }

    public function test_unique_name(): void
    {
        SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_ends_on_after_starts_on(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2027-06-30',
                'ends_on' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    public function test_admin_can_update_and_delete(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/school-years/{$year->id}", [
                'name' => '2026-2027 (modifié)',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', '2026-2027 (modifié)');

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/school-years/{$year->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('school_years', ['id' => $year->id]);
    }
}
