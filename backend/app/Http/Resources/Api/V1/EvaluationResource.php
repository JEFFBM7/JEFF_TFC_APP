<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Evaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Evaluation */
class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'term_id' => $this->term_id,
            'period_id' => $this->period_id,
            'teacher_id' => $this->teacher_id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => Evaluation::typeLabel($this->type),
            'component' => $this->component(),
            'held_on' => $this->held_on?->toDateString(),
            'max_value' => (float) $this->max_value,
            'published_at' => $this->published_at?->toISOString(),
            'is_published' => $this->isPublished(),
            'classroom' => ClassRoomResource::make($this->whenLoaded('classroom')),
            'subject' => SubjectResource::make($this->whenLoaded('subject')),
            'period' => PeriodResource::make($this->whenLoaded('period')),
            'grades_count' => $this->whenCounted('grades'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
