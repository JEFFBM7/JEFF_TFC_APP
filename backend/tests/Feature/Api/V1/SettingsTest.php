<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $teacher = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/admin/settings')
            ->assertForbidden();

        $this->actingAs($teacher, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['settings' => []])
            ->assertForbidden();
    }

    public function test_index_returns_defaults_when_empty(): void
    {
        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $rows = collect($res->json('data'));
        $consecutive = $rows->firstWhere('key', 'attendance.consecutive_threshold');
        $this->assertSame(3, $consecutive['value']);
        $this->assertSame('integer', $consecutive['type']);

        $lowGrade = $rows->firstWhere('key', 'grades.low_average_threshold');
        $this->assertEquals(8.0, $lowGrade['value']);
    }

    public function test_admin_can_update_settings(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    'attendance.consecutive_threshold' => 4,
                    'attendance.rolling_threshold' => 6,
                    'grades.low_average_threshold' => 9.5,
                    'grades.notify_parents_on_low_average' => false,
                ],
            ])
            ->assertOk();

        $this->assertSame(4, AppSetting::get('attendance.consecutive_threshold'));
        $this->assertSame(6, AppSetting::get('attendance.rolling_threshold'));
        $this->assertEquals(9.5, AppSetting::get('grades.low_average_threshold'));
        $this->assertFalse(AppSetting::get('grades.notify_parents_on_low_average'));
    }

    public function test_update_rejects_out_of_range_values(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => ['attendance.consecutive_threshold' => 999],
            ])
            ->assertUnprocessable();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => ['grades.low_average_threshold' => 25],
            ])
            ->assertUnprocessable();
    }

    public function test_update_ignores_unknown_keys(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    'unknown.key' => 42,
                    'attendance.consecutive_threshold' => 4,
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('app_settings', ['key' => 'unknown.key']);
        $this->assertSame(4, AppSetting::get('attendance.consecutive_threshold'));
    }
}
