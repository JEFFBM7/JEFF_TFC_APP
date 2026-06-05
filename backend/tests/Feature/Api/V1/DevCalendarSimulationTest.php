<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\TermGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevCalendarSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulated_primary_term_appears_in_calendar_context(): void
    {
        $year = $this->seedYear();
        $t2 = $year->terms()->where('applicable_cycle', 'primaire')->where('position', 2)->firstOrFail();

        $admin = User::factory()->create(['role' => UserRole::Admin, 'admin_scope' => 'global']);

        $res = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Dev-Calendar-Primary-Term-Id' => (string) $t2->id])
            ->getJson('/api/v1/school-calendar/context?school_year_id='.$year->id)
            ->assertOk();

        $primary = collect($res->json('data.entries'))->firstWhere('cycle', 'primaire');
        $this->assertSame('2ème Trimestre', $primary['term']['name']);
        $this->assertTrue($res->json('data.simulation.active'));
    }

    public function test_dev_options_only_in_local_environment(): void
    {
        $this->app['env'] = 'production';

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-calendar/dev-options')
            ->assertNotFound();
    }

    private function seedYear(): SchoolYear
    {
        $year = SchoolYear::query()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
            'is_current' => true,
        ]);

        app(TermGenerationService::class)->generateForYear($year);

        return $year->fresh();
    }
}
