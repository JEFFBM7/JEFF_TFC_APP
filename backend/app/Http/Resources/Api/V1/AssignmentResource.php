<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TeacherAssignment */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'school_year_id' => $this->school_year_id,
            'term_id' => $this->term_id,
            'weekly_hours' => $this->weekly_hours !== null ? (float) $this->weekly_hours : null,
            'is_main' => $this->is_main,
            'teacher' => TeacherResource::make($this->whenLoaded('teacher')),
            'classroom' => ClassRoomResource::make($this->whenLoaded('classroom')),
            'subject' => SubjectResource::make($this->whenLoaded('subject')),
            'term' => TermResource::make($this->whenLoaded('term')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
