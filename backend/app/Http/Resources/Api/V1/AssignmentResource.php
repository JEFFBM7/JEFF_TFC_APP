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
            'teacher' => TeacherResource::make($this->whenLoaded('teacher')),
            'classroom' => ClassRoomResource::make($this->whenLoaded('classroom')),
            'subject' => SubjectResource::make($this->whenLoaded('subject')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
