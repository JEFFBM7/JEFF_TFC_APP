<?php

namespace App\Support;

use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Subject;
use App\Models\Teacher;

class TeacherSpecialityMatcher
{
    public static function matches(Teacher $teacher, Subject $subject): bool
    {
        return self::normalize($teacher->speciality) === self::normalize($subject->name);
    }

    public static function isPrimaryOrMaternelClassroom(ClassRoom $classroom): bool
    {
        return in_array($classroom->level?->cycle, [Level::CYCLE_MATERNEL, Level::CYCLE_PRIMAIRE], true);
    }

    public static function canAssignToCourse(Teacher $teacher, Subject $subject, ClassRoom $classroom): bool
    {
        if (self::isPrimaryOrMaternelClassroom($classroom)) {
            return $teacher->teacher_type === Teacher::TYPE_PRIMAIRE;
        }

        return $teacher->teacher_type === Teacher::TYPE_SECONDAIRE
            && self::matches($teacher, $subject);
    }

    public static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
