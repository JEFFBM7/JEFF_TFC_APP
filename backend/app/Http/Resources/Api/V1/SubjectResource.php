<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subject */
class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $classroom = $this->relationLoaded('classrooms') ? $this->classrooms->first() : null;
        $assignment = $this->relationLoaded('assignments') ? $this->assignments->first() : null;

        return [
            'id' => $this->id,
            'row_key' => $classroom ? "{$this->id}-{$classroom->id}" : (string) $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'default_coefficient' => (float) $this->default_coefficient,
            'evaluation_type' => $this->evaluation_type,
            'status' => $this->status,
            'coefficient' => $this->whenPivotLoaded(
                'classroom_subject',
                fn () => (float) $this->pivot->coefficient,
                (float) $this->default_coefficient,
            ),
            'classroom_id' => $classroom?->id,
            'school_year_id' => $assignment?->school_year_id,
            'term_id' => $assignment?->term_id,
            'teacher_id' => $assignment?->teacher_id,
            'weekly_hours' => $assignment?->weekly_hours !== null ? (float) $assignment->weekly_hours : null,
            'classroom' => ClassRoomResource::make($classroom),
            'school_year' => SchoolYearResource::make($assignment?->schoolYear),
            'term' => TermResource::make($assignment?->term),
            'teacher' => TeacherResource::make($assignment?->teacher),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
