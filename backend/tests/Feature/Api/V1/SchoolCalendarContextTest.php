<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use App\Services\TermGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolCalendarContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_admin_sees_both_active_calendars(): void
    {
        $year = $this->seedMalungaYear();

        $this->travelTo('2026-01-15');

        $res = $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => 'global',
        ]), 'sanctum')
            ->getJson('/api/v1/school-calendar/context?school_year_id='.$year->id)
            ->assertOk();

        $entries = $res->json('data.entries');
        $this->assertCount(2, $entries);

        $primary = collect($entries)->firstWhere('cycle', Term::CYCLE_PRIMAIRE);
        $secondary = collect($entries)->firstWhere('cycle', Term::CYCLE_SECONDAIRE);

        $this->assertSame('active', $primary['status']);
        $this->assertSame('2ème Trimestre', $primary['term']['name']);
        $this->assertNotNull($primary['period']);

        $this->assertSame('active', $secondary['status']);
        $this->assertSame('1er Semestre', $secondary['term']['name']);
        $this->assertNotNull($secondary['period']);
    }

    public function test_primary_admin_only_sees_primaire_calendar(): void
    {
        $year = $this->seedMalungaYear();

        $this->travelTo('2026-01-15');

        $res = $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => 'primary_maternal',
        ]), 'sanctum')
            ->getJson('/api/v1/school-calendar/context?school_year_id='.$year->id)
            ->assertOk();

        $entries = $res->json('data.entries');
        $this->assertCount(1, $entries);
        $this->assertSame(Term::CYCLE_PRIMAIRE, $entries[0]['cycle']);
        $this->assertSame('2ème Trimestre', $entries[0]['term']['name']);
    }

    public function test_secondary_admin_only_sees_secondaire_calendar(): void
    {
        $year = $this->seedMalungaYear();

        $this->travelTo('2026-01-15');

        $res = $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => 'secondary_technical',
        ]), 'sanctum')
            ->getJson('/api/v1/school-calendar/context?school_year_id='.$year->id)
            ->assertOk();

        $entries = $res->json('data.entries');
        $this->assertCount(1, $entries);
        $this->assertSame(Term::CYCLE_SECONDAIRE, $entries[0]['cycle']);
        $this->assertSame('1er Semestre', $entries[0]['term']['name']);
    }

    private function seedMalungaYear(): SchoolYear
    {
        $year = SchoolYear::query()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
            'is_current' => true,
        ]);

        app(TermGenerationService::class)->generateForYear($year);

        return $year;
    }
}
