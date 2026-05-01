<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        User::factory()->create([
            'email' => 'prof@educonnect.test',
            'password' => Hash::make('secret123'),
            'role' => UserRole::Enseignant,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'prof@educonnect.test',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'prof@educonnect.test')
            ->assertJsonPath('user.role', 'enseignant')
            ->assertJsonStructure(['token', 'token_type', 'user']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'a@b.test',
            'password' => Hash::make('good'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@b.test',
            'password' => 'wrong',
        ]);

        $response->assertUnprocessable();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Parent,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('role', 'parent');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        // Même processus PHPUnit : le guard Sanctum garde l’utilisateur en mémoire entre deux appels HTTP.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
