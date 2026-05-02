<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Password;
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

    // ─── Login logs ──────────────────────────────────────────────────────

    public function test_successful_login_creates_log_entry(): void
    {
        User::factory()->create([
            'email' => 'log@test.com',
            'password' => Hash::make('pass'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'log@test.com',
            'password' => 'pass',
        ])->assertOk();

        $this->assertDatabaseHas('login_logs', [
            'email' => 'log@test.com',
            'success' => true,
        ]);
    }

    public function test_failed_login_creates_log_entry(): void
    {
        User::factory()->create([
            'email' => 'fail@test.com',
            'password' => Hash::make('good'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'fail@test.com',
            'password' => 'bad',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('login_logs', [
            'email' => 'fail@test.com',
            'success' => false,
        ]);
    }

    public function test_admin_can_view_login_logs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        User::factory()->create([
            'email' => 'x@t.com',
            'password' => Hash::make('p'),
        ]);
        $this->postJson('/api/v1/auth/login', ['email' => 'x@t.com', 'password' => 'p']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/login-logs')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Forgot / Reset password ─────────────────────────────────────────

    public function test_forgot_password_sends_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@test.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@test.com',
        ])->assertOk()
            ->assertJsonPath('message', 'Lien de réinitialisation envoyé.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ghost@nobody.test',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'tok@test.com',
            'password' => Hash::make('old'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'tok@test.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk()
            ->assertJsonPath('message', 'Mot de passe réinitialisé avec succès.');

        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        User::factory()->create(['email' => 'bad@test.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => 'bad@test.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertUnprocessable();
    }
}
