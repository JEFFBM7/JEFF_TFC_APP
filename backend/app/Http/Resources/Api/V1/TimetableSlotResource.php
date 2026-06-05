<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimetableSlot */
class TimetableSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'school_year_id' => $this->school_year_id,
            'day_of_week' => $this->day_of_week,
            'starts_at' => substr((string) $this->starts_at, 0, 5),
            'ends_at' => substr((string) $this->ends_at, 0, 5),
            'room' => $this->room,
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'full_name' => $this->classroom->full_name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
            ]),
            'teacher' => $this->whenLoaded('teacher', fn () => [
                'id' => $this->teacher->id,
                'name' => $this->teacher->user?->name,
            ]),
        ];
    }
}
