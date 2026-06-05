<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StudentProfileResolver
{
    /**
     * Certains comptes importés peuvent pointer vers une fiche doublon d'une autre année.
     * Si l'année courante contient une seule fiche non liée avec les mêmes éléments de nom,
     * le portail utilise cette fiche réelle pour les absences, bulletins et emploi du temps.
     */
    public static function forCurrentSchoolYear(Student $student, Request $request): Student
    {
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        if ($schoolYearId === null || (int) $student->enrollment_school_year_id === (int) $schoolYearId) {
            return $student;
        }

        $tokens = self::identityTokens($student);
        if (count($tokens) < 2) {
            return $student;
        }

        $candidates = Student::query()
            ->whereNull('user_id')
            ->where('enrollment_school_year_id', $schoolYearId)
            ->with('classroom.level')
            ->get()
            ->filter(fn (Student $candidate) => self::identityTokens($candidate) === $tokens)
            ->values();

        if ($candidates->count() !== 1) {
            return $student;
        }

        return $candidates->first();
    }

    /**
     * @return list<string>
     */
    public static function identityTokens(Student $student): array
    {
        return collect([$student->last_name, $student->middle_name, $student->first_name])
            ->flatMap(function (?string $part): array {
                $normalized = Str::of((string) $part)
                    ->ascii()
                    ->lower()
                    ->squish()
                    ->value();

                return $normalized === '' ? [] : explode(' ', $normalized);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
