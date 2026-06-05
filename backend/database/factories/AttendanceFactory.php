<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attendance> */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'classroom_id' => null,
            'subject_id' => null,
            'date' => now()->toDateString(),
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
            'justification' => null,
        ];
    }
}
