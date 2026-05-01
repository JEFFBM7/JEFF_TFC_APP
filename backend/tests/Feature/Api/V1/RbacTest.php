<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    // --- /api/v1/admin/ping ---

    public function test_admin_ping_rejects_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/ping')->assertUnauthorized();
    }

    public function test_admin_ping_rejects_parent(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/ping')
            ->assertForbidden();
    }

    public function test_admin_ping_rejects_enseignant(): void
    {
        $user = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/ping')
            ->assertForbidden();
    }

    public function test_admin_ping_rejects_eleve(): void
    {
        $user = User::factory()->create(['role' => UserRole::Eleve]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/ping')
            ->assertForbidden();
    }

    public function test_admin_ping_allows_admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/ping')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    // --- /api/v1/staff/ping ---

    public function test_staff_ping_rejects_parent(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/staff/ping')
            ->assertForbidden();
    }

    public function test_staff_ping_allows_secretariat(): void
    {
        $user = User::factory()->create(['role' => UserRole::Secretariat]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/staff/ping')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_staff_ping_allows_admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/staff/ping')
            ->assertOk();
    }
}
