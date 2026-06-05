<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Grade */
class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_id' => $this->evaluation_id,
            'student_id' => $this->student_id,
            'value' => $this->value !== null ? (float) $this->value : null,
            'absent' => $this->absent,
            'student' => StudentResource::make($this->whenLoaded('student')),
            'updated_at' => $this->updated_at,
        ];
    }
}
