<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        AdminScopeContext::assertGlobalAdmin($request->user());

        $query = User::query()->orderBy('name');

        if ($request->filled('role')) {
            $role = UserRole::tryFrom($request->string('role')->value());
            if ($role) {
                $query->where('role', $role->value);
            }
        }

        // Filtre dédié aux admins secondaires (cycle Primaire/Maternel ou Secondaire/Technique).
        if ($request->boolean('secondary_admins_only')) {
            $query->where('role', UserRole::Admin->value)
                ->whereIn('admin_scope', [
                    AdminScopeContext::PRIMARY_MATERNAL,
                    AdminScopeContext::SECONDARY_TECHNICAL,
                ]);
        } elseif ($request->filled('admin_scope')) {
            $query->where('role', UserRole::Admin->value)
                ->where('admin_scope', $request->string('admin_scope')->value());
        }

        $paginated = $query->paginate(100);

        return response()->json([
            'data' => $paginated->map(fn (User $u) => self::serialize($u))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        AdminScopeContext::assertGlobalAdmin($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'admin_scope' => [
                Rule::requiredIf(fn () => $request->input('role') === UserRole::Admin->value),
                'nullable',
                Rule::in(AdminScopeContext::SCOPES),
            ],
        ]);

        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from($data['role']);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'admin_scope' => $role === UserRole::Admin ? $data['admin_scope'] : null,
        ]);

        return response()->json(self::serialize($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        AdminScopeContext::assertGlobalAdmin($request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'admin_scope' => ['sometimes', 'required', Rule::in([
                AdminScopeContext::PRIMARY_MATERNAL,
                AdminScopeContext::SECONDARY_TECHNICAL,
            ])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Nom / email / périmètre : édition réservée aux admins secondaires
        // (leur écran dédié). L'activation/désactivation, elle, s'applique à
        // n'importe quel utilisateur depuis la liste des utilisateurs.
        if (array_intersect(array_keys($data), ['name', 'email', 'admin_scope'])) {
            self::assertSecondaryAdmin($user);
        }

        // Anti-verrouillage : un admin ne peut pas se désactiver lui-même.
        if (array_key_exists('is_active', $data)
            && ! $data['is_active']
            && $request->user()?->id === $user->id) {
            abort(422, 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        $user->fill($data)->save();

        return response()->json(self::serialize($user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        AdminScopeContext::assertGlobalAdmin($request->user());
        self::assertSecondaryAdmin($user);

        if ($request->user()?->id === $user->id) {
            abort(422, 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $user->delete();

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        AdminScopeContext::assertGlobalAdmin($request->user());
        self::assertSecondaryAdmin($user);

        $data = $request->validate([
            'password' => ['required', Password::min(8)],
        ]);

        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json(['ok' => true]);
    }

    private static function assertSecondaryAdmin(User $user): void
    {
        $isSecondary = $user->role === UserRole::Admin
            && in_array($user->admin_scope, [
                AdminScopeContext::PRIMARY_MATERNAL,
                AdminScopeContext::SECONDARY_TECHNICAL,
            ], true);

        if (! $isSecondary) {
            abort(403, 'Action réservée aux administrateurs secondaires.');
        }
    }

    /** @return array<string, mixed> */
    private static function serialize(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role->value,
            'is_active' => (bool) $u->is_active,
            ...AdminScopeContext::userPayload($u),
        ];
    }
}
