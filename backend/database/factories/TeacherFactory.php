<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Teacher> */
class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->create(['role' => UserRole::Enseignant])->id,
            'teacher_type' => Teacher::TYPE_SECONDAIRE,
            'registration_number' => 'ENS-'.fake()->unique()->numerify('####'),
            'gender' => fake()->randomElement(['F', 'M']),
            'birth_date' => fake()->date(),
            'address' => fake()->streetAddress(),
            'grade' => fake()->randomElement(['Professeur', 'Instituteur', 'Directeur']),
            'contract_type' => fake()->randomElement(['Permanent', 'Vacataire']),
            'hired_on' => fake()->date(),
            'speciality' => $this->faker->randomElement(['Mathématiques', 'Français', 'Sciences']),
            'phone' => $this->faker->phoneNumber(),
        ];
    }
}
