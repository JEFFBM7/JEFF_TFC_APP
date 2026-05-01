<?php

namespace Database\Factories;

use App\Models\ClassRoom;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassRoom> */
class ClassRoomFactory extends Factory
{
    protected $model = ClassRoom::class;

    public function definition(): array
    {
        return [
            'level_id' => Level::factory(),
            'section' => $this->faker->randomElement(['A', 'B', 'C', 'D']),
            'capacity' => $this->faker->numberBetween(25, 45),
        ];
    }
}
