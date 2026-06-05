<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use App\Services\TermGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_school_year_generates_malunga_calendar_structure(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2025-2026',
                'starts_on' => '2025-09-01',
                'ends_on' => '2026-07-31',
                'is_current' => true,
            ])
            ->assertCreated();

        $year = SchoolYear::query()->where('name', '2025-2026')->firstOrFail();

        $this->assertSame(5, Term::query()->where('school_year_id', $year->id)->count());
        $this->assertSame(10, Period::query()->where('school_year_id', $year->id)->count());

        $t1 = Term::query()->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_PRIMAIRE)->where('position', 1)->firstOrFail();
        $this->assertSame(Term::TYPE_TRIMESTRE, $t1->term_type);
        $this->assertSame('1er Trimestre', $t1->name);
        $this->assertSame('2025-09-01', $t1->starts_on->toDateString());
        $this->assertSame('2025-12-17', $t1->ends_on->toDateString());

        $t2 = Term::query()->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_PRIMAIRE)->where('position', 2)->firstOrFail();
        $this->assertSame('2026-01-05', $t2->starts_on->toDateString());
        $this->assertSame('2026-03-27', $t2->ends_on->toDateString());

        $t3 = Term::query()->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_PRIMAIRE)->where('position', 3)->firstOrFail();
        $this->assertSame('2026-04-13', $t3->starts_on->toDateString());
        $this->assertSame('2026-07-02', $t3->ends_on->toDateString());

        $s1 = Term::query()->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_SECONDAIRE)->where('position', 4)->firstOrFail();
        $this->assertSame(Term::TYPE_SEMESTRE, $s1->term_type);
        $this->assertSame('1er Semestre', $s1->name);
        $this->assertSame('2025-09-01', $s1->starts_on->toDateString());
        $this->assertSame('2026-02-11', $s1->ends_on->toDateString());

        $s2 = Term::query()->where('school_year_id', $year->id)
            ->where('applicable_cycle', Term::CYCLE_SECONDAIRE)->where('position', 5)->firstOrFail();
        $this->assertSame('2026-02-12', $s2->starts_on->toDateString());
        $this->assertSame('2026-07-02', $s2->ends_on->toDateString());

        $primaryPeriods = Period::query()->where('school_year_id', $year->id)->orderBy('position')->take(6)->pluck('position')->all();
        $this->assertSame([1, 2, 3, 4, 5, 6], $primaryPeriods);

        $secondaryPeriods = Period::query()->where('school_year_id', $year->id)->orderBy('position')->skip(6)->take(4)->pluck('position')->all();
        $this->assertSame([7, 8, 9, 10], $secondaryPeriods);
    }

    public function test_regeneration_is_idempotent(): void
    {
        $year = SchoolYear::factory()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-02',
        ]);

        $service = app(TermGenerationService::class);
        $service->generateForYear($year);
        $service->generateForYear($year->fresh());

        $this->assertSame(5, Term::query()->where('school_year_id', $year->id)->count());
        $this->assertSame(10, Period::query()->where('school_year_id', $year->id)->count());
    }

    public function test_semestre_period_positions_are_offset_after_primary(): void
    {
        $year = SchoolYear::factory()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-02',
        ]);

        app(TermGenerationService::class)->generateForYear($year);

        $s1 = Term::query()->where('school_year_id', $year->id)->where('position', 4)->firstOrFail();

        $this->assertSame([7, 8], Period::positionsForTerm($s1));
        $this->assertSame(
            ['Période 1', 'Période 2'],
            $s1->periods()->orderBy('position')->pluck('name')->all(),
        );
    }
}
