<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ClassRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassRoom */
class ClassRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_id' => $this->level_id,
            'school_option_id' => $this->school_option_id,
            'section' => $this->section,
            'option' => $this->option,
            'full_name' => $this->full_name,
            'capacity' => $this->capacity ?? 40,
            'school_class_id' => $this->school_class_id,
            'active' => $this->active ?? true,
            'student_count' => (int) ($this->students_count ?? 0),
            'main_teacher' => $this->main_teacher,
            'grade_average' => $this->grade_average,
            'current_school_year_id' => $this->current_school_year_id,
            'level' => LevelResource::make($this->whenLoaded('level')),
            'school_option' => SchoolOptionResource::make($this->whenLoaded('schoolOption')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
