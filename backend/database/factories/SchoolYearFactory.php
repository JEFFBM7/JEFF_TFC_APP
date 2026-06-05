<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolYear>
 */
class SchoolYearFactory extends Factory
{
    protected $model = SchoolYear::class;

    public function definition(): array
    {
        $start = $this->faker->numberBetween(2018, 2030);

        return [
            'name' => sprintf('%d-%d', $start, $start + 1),
            'starts_on' => sprintf('%d-09-01', $start),
            'ends_on' => sprintf('%d-06-30', $start + 1),
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'is_current' => false,
            'closed_at' => now(),
            'archived_at' => now(),
        ]);
    }
}
