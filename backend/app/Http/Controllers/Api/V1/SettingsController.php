<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Paramétrage applicatif (admin uniquement).
 *
 * Expose la liste des clés autorisées + valeurs courantes, et permet
 * un update groupé. Les seuils numériques sont validés selon les contraintes
 * déclarées dans `AppSetting::KEYS`.
 *
 * Les clés contiennent des points (ex. `attendance.consecutive_threshold`)
 * incompatibles avec la notation imbriquée du Validator de Laravel — la
 * validation est donc faite manuellement par clé.
 */
class SettingsController extends Controller
{
    /** Liste des paramètres avec valeur courante + métadonnées. */
    public function index(): JsonResponse
    {
        $values = AppSetting::allValues();
        $rows = [];
        foreach (AppSetting::KEYS as $key => $meta) {
            $rows[] = [
                'key' => $key,
                'value' => $values[$key],
                'default' => $meta['default'],
                'type' => $meta['type'],
                'description' => $meta['description'] ?? null,
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /** Update bulk : `{ settings: { key: value, ... } }`. */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $errors = [];
        $payload = [];

        foreach ((array) $request->input('settings', []) as $key => $value) {
            if (! array_key_exists($key, AppSetting::KEYS)) {
                continue; // clés inconnues ignorées (forward-compat)
            }
            $meta = AppSetting::KEYS[$key];

            $rule = match ($meta['type']) {
                'integer' => ['required', 'integer', 'min:'.($meta['min'] ?? 0), 'max:'.($meta['max'] ?? PHP_INT_MAX)],
                'float' => ['required', 'numeric', 'min:'.($meta['min'] ?? 0), 'max:'.($meta['max'] ?? 999)],
                'boolean' => ['required', 'boolean'],
                default => ['required'],
            };

            $validator = Validator::make(['value' => $value], ['value' => $rule]);
            if ($validator->fails()) {
                $errors["settings.$key"] = $validator->errors()->get('value');

                continue;
            }

            $payload[$key] = match ($meta['type']) {
                'integer' => (int) $value,
                'float' => (float) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value,
            };
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($payload as $key => $value) {
            AppSetting::set($key, $value);
        }

        AppSetting::flushCache();

        return $this->index();
    }
}
