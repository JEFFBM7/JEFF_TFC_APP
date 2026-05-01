<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subject> */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    private static array $names = ['Mathématiques', 'Français', 'Histoire-Géo', 'Sciences', 'Anglais', 'Physique'];
    private static int $i = 0;

    public function definition(): array
    {
        return [
            'name' => self::$names[self::$i++ % count(self::$names)] . ' ' . self::$i,
            'description' => null,
        ];
    }
}
