<?php

namespace Database\Factories;

use App\Models\Period;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    protected $model = Period::class;

    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'name' => 'Période '.$this->faker->numberBetween(1, 2),
            'position' => $this->faker->numberBetween(1, 2),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-10-31',
        ];
    }
}
