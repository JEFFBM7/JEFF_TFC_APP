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
            'speciality' => $this->faker->randomElement(['Mathématiques', 'Français', 'Sciences']),
            'phone' => $this->faker->phoneNumber(),
        ];
    }
}
