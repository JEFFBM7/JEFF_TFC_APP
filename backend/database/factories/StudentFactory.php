<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'classroom_id' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'middle_name' => $this->faker->lastName(),
            'date_of_birth' => $this->faker->dateTimeBetween('-18 years', '-6 years')->format('Y-m-d'),
            'place_of_birth' => $this->faker->city(),
            'gender' => $this->faker->randomElement(['F', 'M']),
            'nationality' => 'Congolaise',
            'registration_number' => null,
            'photo_path' => null,
            'enrollment_status' => 'actif',
            'order_number' => $this->faker->unique()->numerify('ORD-####'),
            'enrolled_on' => $this->faker->dateTimeBetween('-2 years')->format('Y-m-d'),
            'previous_school' => null,
            'father_name' => $this->faker->name('male'),
            'mother_name' => $this->faker->name('female'),
            'legal_guardian_name' => null,
            'guardian_relationship' => null,
            'primary_phone' => $this->faker->phoneNumber(),
            'secondary_phone' => null,
            'parent_email' => null,
            'residential_address' => $this->faker->streetAddress(),
            'father_profession' => null,
            'mother_profession' => null,
            'notes' => null,
        ];
    }
}
