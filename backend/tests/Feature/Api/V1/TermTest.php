<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_parent_cannot_list_terms(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/terms')
            ->assertForbidden();
    }

    public function test_admin_can_create_term(): void
    {
        $year = SchoolYear::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/terms', [
                'school_year_id' => $year->id,
                'name' => 'Trimestre 1',
                'position' => 1,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-15',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Trimestre 1')
            ->assertJsonPath('data.position', 1);
    }

    public function test_position_unique_per_year(): void
    {
        $year = SchoolYear::factory()->create();
        Term::factory()->create([
            'school_year_id' => $year->id,
            'position' => 1,
            'name' => 'T1',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/terms', [
                'school_year_id' => $year->id,
                'name' => 'Trimestre 1 bis',
                'position' => 1,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position']);
    }

    public function test_filter_by_school_year(): void
    {
        $year1 = SchoolYear::factory()->create();
        $year2 = SchoolYear::factory()->create();

        Term::factory()->create(['school_year_id' => $year1->id, 'position' => 1, 'name' => 'A']);
        Term::factory()->create(['school_year_id' => $year2->id, 'position' => 1, 'name' => 'B']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/terms?school_year_id='.$year1->id)
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A', $response->json('data.0.name'));
    }

    public function test_cascade_delete_when_school_year_deleted(): void
    {
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'position' => 1,
            'name' => 'T1',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/school-years/{$year->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('terms', ['id' => $term->id]);
    }
}
