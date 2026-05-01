<?php

namespace Database\Factories;

use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Level> */
class LevelFactory extends Factory
{
    protected $model = Level::class;

    private static int $counter = 0;

    public function definition(): array
    {
        return [
            'name' => '6ème ' . (++self::$counter),
            'order' => self::$counter,
        ];
    }
}
