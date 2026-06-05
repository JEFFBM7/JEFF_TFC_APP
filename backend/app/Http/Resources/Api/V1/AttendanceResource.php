<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isJustifiableStatus = in_array($this->status, [
            Attendance::STATUS_ABSENT,
            Attendance::STATUS_LATE,
        ], true);
        $studentSubmitted = filled($this->student_justification);
        $canStudentJustify = $isJustifiableStatus
            && ! $this->justified
            && $this->date !== null
            && $this->date->copy()->endOfDay()->greaterThanOrEqualTo(now());

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'justified' => $this->justified,
            'justification' => $this->justification,
            'student_justification' => $this->student_justification,
            'student_justified_at' => $this->student_justified_at,
            'justification_status' => $this->justified
                ? 'confirmed'
                : ($studentSubmitted ? 'pending_parent' : ($canStudentJustify ? 'awaiting_student' : 'expired')),
            'can_student_justify' => $canStudentJustify,
            'can_parent_confirm' => $isJustifiableStatus && ! $this->justified && $studentSubmitted,
            'justified_at' => $this->justified_at,
            'student' => StudentResource::make($this->whenLoaded('student')),
            'subject' => SubjectResource::make($this->whenLoaded('subject')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
