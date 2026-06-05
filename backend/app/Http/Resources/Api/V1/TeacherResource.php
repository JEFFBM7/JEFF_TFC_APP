<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Level;
use App\Models\Teacher;
use App\Http\Requests\Api\V1\TeacherRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Teacher */
class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mainAssignment = $this->relationLoaded('assignments')
            ? $this->assignments->firstWhere('is_main', true)
            : null;
        $subjectAssignments = $this->relationLoaded('assignments')
            ? $this->assignments->whereNotNull('subject_id')->values()
            : collect();
        $courseCount = $subjectAssignments->count();
        if (
            $this->teacher_type === Teacher::TYPE_PRIMAIRE
            && $mainAssignment !== null
            && $courseCount === 0
            && $mainAssignment->relationLoaded('classroom')
            && $mainAssignment->classroom !== null
        ) {
            $mainAssignment->classroom->loadMissing('subjects');
            $courseCount = $mainAssignment->classroom->subjects->count();
        }

        $cycle = $this->resolveTeacherCycle($mainAssignment, $subjectAssignments);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'teacher_type' => $this->teacher_type,
            'registration_number' => $this->registration_number,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'address' => $this->address,
            'grade' => $this->grade,
            'contract_type' => $this->contract_type,
            'hired_on' => $this->hired_on?->format('Y-m-d'),
            'speciality' => $this->speciality,
            'assigned_courses_count' => $this->when($this->relationLoaded('assignments'), $courseCount),
            'phone' => $this->phone,
            'main_classroom' => $this->when($mainAssignment !== null, fn () => [
                'id' => $mainAssignment->classroom_id,
                'full_name' => $mainAssignment->classroom?->full_name,
            ]),
            'assigned_classrooms' => $this->when(
                $this->teacher_type === Teacher::TYPE_SECONDAIRE && $this->relationLoaded('assignments'),
                fn () => $subjectAssignments
                    ->map(fn ($assignment) => [
                        'id' => $assignment->classroom_id,
                        'full_name' => $assignment->classroom?->full_name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
            ),
            'subject' => $this->when(
                $this->teacher_type === Teacher::TYPE_SECONDAIRE
                    && $subjectAssignments->isNotEmpty()
                    && $mainAssignment === null,
                fn () => [
                    'id' => $subjectAssignments->first()->subject_id,
                    'name' => $subjectAssignments->first()->subject?->name ?? $this->speciality,
                ],
            ),
            'secondary_role' => $this->when(
                $this->teacher_type === Teacher::TYPE_SECONDAIRE && $this->relationLoaded('assignments'),
                fn () => $mainAssignment !== null
                    ? TeacherRequest::SECONDARY_ROLE_PRINCIPAL
                    : TeacherRequest::SECONDARY_ROLE_SPECIALIST,
            ),
            'cycle' => $this->when($this->relationLoaded('assignments'), $cycle),
            'assignment_role' => $this->when(
                $this->relationLoaded('assignments'),
                $mainAssignment !== null ? 'principal' : 'intervenant',
            ),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\TeacherAssignment>  $subjectAssignments
     */
    private function resolveTeacherCycle($mainAssignment, $subjectAssignments): ?string
    {
        if (
            $mainAssignment !== null
            && $mainAssignment->relationLoaded('classroom')
            && $mainAssignment->classroom?->relationLoaded('level')
            && $mainAssignment->classroom->level !== null
        ) {
            return $mainAssignment->classroom->level->cycle;
        }

        $firstSubjectAssignment = $subjectAssignments->first();
        if (
            $firstSubjectAssignment !== null
            && $firstSubjectAssignment->relationLoaded('classroom')
            && $firstSubjectAssignment->classroom?->relationLoaded('level')
            && $firstSubjectAssignment->classroom->level !== null
        ) {
            return $firstSubjectAssignment->classroom->level->cycle;
        }

        return match ($this->teacher_type) {
            Teacher::TYPE_PRIMAIRE => Level::CYCLE_PRIMAIRE,
            Teacher::TYPE_SECONDAIRE => Level::CYCLE_SECONDAIRE,
            default => null,
        };
    }
}
