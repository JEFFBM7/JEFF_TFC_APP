<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Paramètre applicatif (clé/valeur).
 *
 * Stocke notamment les seuils d'alerte configurables par l'administrateur :
 * - absences (consécutives, glissantes)
 * - retards
 * - moyenne faible
 *
 * Les valeurs sont stockées en JSON pour supporter les types non scalaires.
 * Un cache mémoire évite les requêtes répétées dans une même requête HTTP.
 */
class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'description'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /** @var array<string, mixed>|null */
    protected static ?array $cache = null;

    /** Clés autorisées + valeurs par défaut. */
    public const KEYS = [
        'attendance.consecutive_threshold' => [
            'default' => 3,
            'type' => 'integer',
            'min' => 1,
            'max' => 30,
            'description' => 'Nombre d\'absences injustifiées consécutives déclenchant une alerte.',
        ],
        'attendance.rolling_threshold' => [
            'default' => 5,
            'type' => 'integer',
            'min' => 1,
            'max' => 50,
            'description' => 'Nombre d\'absences injustifiées sur la fenêtre glissante déclenchant une alerte.',
        ],
        'attendance.rolling_window_days' => [
            'default' => 30,
            'type' => 'integer',
            'min' => 7,
            'max' => 180,
            'description' => 'Taille de la fenêtre glissante d\'absences (en jours).',
        ],
        'attendance.late_threshold' => [
            'default' => 5,
            'type' => 'integer',
            'min' => 1,
            'max' => 50,
            'description' => 'Nombre de retards déclenchant une alerte.',
        ],
        'attendance.late_window_days' => [
            'default' => 30,
            'type' => 'integer',
            'min' => 7,
            'max' => 180,
            'description' => 'Taille de la fenêtre des retards (en jours).',
        ],
        'grades.low_average_threshold' => [
            'default' => 8.0,
            'type' => 'float',
            'min' => 0,
            'max' => 20,
            'description' => 'Moyenne en dessous de laquelle un élève est considéré en difficulté.',
        ],
        'grades.notify_parents_on_low_average' => [
            'default' => true,
            'type' => 'boolean',
            'description' => 'Envoyer une notification aux parents à la clôture d\'un trimestre lorsque la moyenne est sous le seuil.',
        ],
        'promotion.pass_average_threshold' => [
            'default' => 10.0,
            'type' => 'float',
            'min' => 0,
            'max' => 20,
            'description' => 'Moyenne minimale (sur 20) pour valider l\'année et passer en classe supérieure ; en dessous, l\'élève redouble.',
        ],
    ];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        if (! array_key_exists($key, self::KEYS)) {
            return $fallback;
        }

        if (app()->runningUnitTests()) {
            self::flushCache();
        }

        if (self::$cache === null) {
            self::$cache = self::query()->get()->pluck('value', 'key')->all();
        }

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return $fallback ?? self::KEYS[$key]['default'];
    }

    public static function set(string $key, mixed $value): void
    {
        if (! array_key_exists($key, self::KEYS)) {
            return;
        }

        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => self::KEYS[$key]['description'] ?? null],
        );

        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string, mixed> */
    public static function allValues(): array
    {
        $stored = self::query()->get()->pluck('value', 'key')->all();
        $result = [];
        foreach (self::KEYS as $key => $meta) {
            $result[$key] = $stored[$key] ?? $meta['default'];
        }

        return $result;
    }
}
