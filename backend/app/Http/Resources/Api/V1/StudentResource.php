<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Student;
use App\Services\StudentPortalAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $portalAccounts = app(StudentPortalAccountService::class);

        // Si l'inscription de l'année consultée a été chargée (liste filtrée par
        // année), on présente la classe + le statut de CETTE année plutôt que le
        // cache année-courante (students.classroom_id / enrollment_status).
        $yearEnrollment = $this->relationLoaded('enrollments') ? $this->enrollments->first() : null;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'classroom_id' => $yearEnrollment ? $yearEnrollment->classroom_id : $this->classroom_id,
            'enrollment_school_year_id' => $this->enrollment_school_year_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'place_of_birth' => $this->place_of_birth,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'registration_number' => $this->registration_number,
            'photo_path' => $this->photo_path,
            'enrollment_status' => $yearEnrollment ? $yearEnrollment->status : $this->enrollment_status,
            'order_number' => $this->order_number,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
            'previous_school' => $this->previous_school,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'legal_guardian_name' => $this->legal_guardian_name,
            'guardian_relationship' => $this->guardian_relationship,
            'primary_phone' => $this->primary_phone,
            'secondary_phone' => $this->secondary_phone,
            'parent_email' => $this->parent_email,
            'residential_address' => $this->residential_address,
            'father_profession' => $this->father_profession,
            'mother_profession' => $this->mother_profession,
            'notes' => $this->notes,
            'classroom' => $yearEnrollment
                ? ($yearEnrollment->classroom ? ClassRoomResource::make($yearEnrollment->classroom) : null)
                : ClassRoomResource::make($this->whenLoaded('classroom')),
            'enrollment_school_year' => SchoolYearResource::make($this->whenLoaded('enrollmentSchoolYear')),
            'parents' => ParentResource::collection($this->whenLoaded('parents')),
            'student_portal_status' => $portalAccounts->status($this->resource),
            'student_portal_eligible' => $portalAccounts->isEligible($this->resource),
            'relation' => $this->whenPivotLoaded('parent_student', fn () => $this->pivot->relation),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
