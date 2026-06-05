<?php

namespace App\Services;

use App\Models\SchoolYear;
use App\Models\Teacher;
use Illuminate\Support\Str;

class TeacherRegistrationNumberService
{
    public function assignIfMissing(Teacher $teacher): Teacher
    {
        if (filled($teacher->registration_number)) {
            return $teacher;
        }

        $teacher->forceFill([
            'registration_number' => $this->generate($teacher),
        ])->save();

        return $teacher->fresh();
    }

    public function generate(Teacher $teacher): string
    {
        $year = $this->resolveYear();
        $segment = match ($teacher->teacher_type) {
            Teacher::TYPE_PRIMAIRE => 'PRI',
            Teacher::TYPE_SECONDAIRE => 'SEC',
            default => 'ENS',
        };
        $base = sprintf('ENS-%s-%s-%05d', $segment, $year, $teacher->id);

        if (! Teacher::query()->where('registration_number', $base)->whereKeyNot($teacher->id)->exists()) {
            return $base;
        }

        return sprintf('ENS-%s-%s-%05d-%s', $segment, $year, $teacher->id, Str::upper(Str::random(3)));
    }

    private function resolveYear(): string
    {
        $startDate = SchoolYear::query()->current()->value('starts_on');

        return $startDate ? substr((string) $startDate, 0, 4) : now()->format('Y');
    }
}
