<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureRole
{
    /**
     * Un ou plusieurs rôles autorisés, séparés par une virgule.
     * Ex : Route::middleware('role:admin') ou Route::middleware('role:admin,secretariat')
     */
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $allowed = array_map(
            fn (string $r) => UserRole::tryFrom($r),
            $roles,
        );

        if (! in_array($user->role, $allowed, true)) {
            return response()->json([
                'message' => 'Accès refusé : permission insuffisante.',
            ], 403);
        }

        return $next($request);
    }
}
