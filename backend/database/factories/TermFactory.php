<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    protected $model = Term::class;

    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'name' => 'Trimestre '.$this->faker->numberBetween(1, 3),
            'position' => $this->faker->numberBetween(1, 3),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
        ];
    }
}
