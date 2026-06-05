<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'level_id' => Level::factory(),
            'school_option_id' => null,
            'name' => $this->faker->unique()->bothify('CL-##??'),
            'active' => true,
        ];
    }
}
