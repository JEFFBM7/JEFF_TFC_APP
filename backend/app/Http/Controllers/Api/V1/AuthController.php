<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            LoginLog::log($validated['email'], $request, false);

            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'spa';
        $token = $user->createToken($deviceName)->plainTextToken;

        LoginLog::log($user->email, $request, true, $user->id);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ]);
    }

    /** Envoie un lien de réinitialisation (CDC §4.1 / UC-01). */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Lien de réinitialisation envoyé.']);
    }

    /** Réinitialise le mot de passe avec le token reçu par e-mail. */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $accessToken = PersonalAccessToken::findToken($bearer);
            $accessToken?->delete();
        } else {
            $request->user()?->currentAccessToken()?->delete();
        }

        return response()->json(['message' => 'ok']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
        ]);
    }

    /** Journal des connexions (CDC §4.1 — journalisation). */
    public function loginLogs(Request $request): JsonResponse
    {
        $query = LoginLog::query()->with('user:id,name,email,role');

        if ($request->filled('email')) {
            $query->where('email', $request->string('email')->value());
        }
        if ($request->filled('success')) {
            $query->where('success', $request->boolean('success'));
        }

        $logs = $query->orderByDesc('attempted_at')->paginate(50);

        return response()->json($logs);
    }
}
