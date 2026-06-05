<?php

namespace Database\Factories;

use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Evaluation> */
class EvaluationFactory extends Factory
{
    protected $model = Evaluation::class;

    public function definition(): array
    {
        return [
            'classroom_id' => ClassRoom::factory(),
            'subject_id' => Subject::factory(),
            'term_id' => Term::factory(),
            'period_id' => null,
            'teacher_id' => null,
            'name' => 'Évaluation '.$this->faker->numberBetween(1, 99),
            'type' => $this->faker->randomElement(Evaluation::TYPES),
            'held_on' => now()->toDateString(),
            'max_value' => 20,
        ];
    }
}
