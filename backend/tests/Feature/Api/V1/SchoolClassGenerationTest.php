<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolClassGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_creating_school_year_generates_base_classes(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.school_classes_count', 75);

        $year = SchoolYear::query()->where('name', '2026-2027')->firstOrFail();

        $this->assertSame(75, SchoolClass::query()->where('school_year_id', $year->id)->count());
        $this->assertSame(11, SchoolClass::query()->where('school_year_id', $year->id)->whereNull('school_option_id')->count());
        $this->assertSame(64, SchoolClass::query()->where('school_year_id', $year->id)->whereNotNull('school_option_id')->count());
        $this->assertDatabaseHas('school_classes', ['school_year_id' => $year->id, 'name' => '9S - SMATH']);
    }

    public function test_generation_is_idempotent_and_creates_default_divisions(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-classes")
            ->assertCreated()
            ->assertJsonPath('meta.count', 75);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-classes")
            ->assertCreated()
            ->assertJsonPath('meta.count', 75);

        $this->assertSame(75, SchoolClass::query()->where('school_year_id', $year->id)->count());
        $this->assertSame(
            75,
            ClassRoom::query()
                ->whereIn('school_class_id', SchoolClass::query()->where('school_year_id', $year->id)->pluck('id'))
                ->count(),
        );
    }

    public function test_admin_can_add_divisions_to_school_class(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
            ])
            ->assertCreated();

        $year = SchoolYear::query()->where('name', '2026-2027')->firstOrFail();
        $class = SchoolClass::query()
            ->where('school_year_id', $year->id)
            ->whereHas('level', fn ($query) => $query->where('cycle', Level::CYCLE_PRIMAIRE))
            ->whereNull('school_option_id')
            ->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-classes/{$class->id}/divisions", [
                'count' => 2,
                'capacity' => 40,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.section', 'A')
            ->assertJsonPath('data.1.section', 'B');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-classes/{$class->id}/divisions/next", [
                'capacity' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('data.section', 'C');

        $this->assertSame(3, ClassRoom::query()->where('school_class_id', $class->id)->count());
    }

    public function test_archived_year_rejects_generation_and_divisions(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2026-2027']);
        $class = SchoolClass::factory()->create(['school_year_id' => $year->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/generate-classes")
            ->assertStatus(423);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/school-classes/{$class->id}/divisions", [
                'count' => 1,
                'capacity' => 40,
            ])
            ->assertStatus(423);
    }
}
