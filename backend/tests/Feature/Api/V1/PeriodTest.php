<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function term(): Term
    {
        $year = SchoolYear::factory()->create();

        return Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 1',
            'position' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
        ]);
    }

    private function termTwo(SchoolYear $year): Term
    {
        return Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 2',
            'position' => 2,
            'starts_on' => '2027-01-06',
            'ends_on' => '2027-03-31',
        ]);
    }

    public function test_admin_can_create_period(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/periods', [
                'term_id' => $term->id,
                'name' => 'Période 1',
                'position' => 1,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-10-31',
            ])
            ->assertCreated()
            ->assertJsonPath('data.term_id', $term->id)
            ->assertJsonPath('data.position', 1);
    }

    public function test_second_term_uses_periods_three_and_four(): void
    {
        $year = SchoolYear::factory()->create();
        $term = $this->termTwo($year);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/periods', [
                'term_id' => $term->id,
                'name' => 'Période 3',
                'position' => 3,
                'starts_on' => '2027-01-06',
                'ends_on' => '2027-02-14',
            ])
            ->assertCreated()
            ->assertJsonPath('data.position', 3);
    }

    public function test_period_position_must_match_term_rank(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/periods', [
                'term_id' => $term->id,
                'name' => 'Période 3',
                'position' => 3,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-10-31',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position']);
    }

    public function test_third_period_in_same_term_is_rejected(): void
    {
        $term = $this->term();
        Period::factory()->create(['term_id' => $term->id, 'name' => 'Période 1', 'position' => 1]);
        Period::factory()->create(['term_id' => $term->id, 'name' => 'Période 2', 'position' => 2]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/periods', [
                'term_id' => $term->id,
                'name' => 'Période bis',
                'position' => 2,
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-12-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position']);
    }

    public function test_period_must_stay_inside_term_bounds(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/periods', [
                'term_id' => $term->id,
                'name' => 'Période 1',
                'position' => 1,
                'starts_on' => '2026-08-25',
                'ends_on' => '2026-10-31',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on']);
    }

    public function test_cannot_delete_period_with_evaluations(): void
    {
        $term = $this->term();
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'Période 1', 'position' => 1]);
        Evaluation::factory()->create(['term_id' => $term->id, 'period_id' => $period->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/periods/{$period->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    public function test_admin_can_close_period(): void
    {
        $term = $this->term();
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'Période 1', 'position' => 1]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/periods/{$period->id}/close")
            ->assertOk()
            ->assertJsonPath('message', 'Période clôturée.');

        $this->assertNotNull($period->fresh()->closed_at);
    }
}
