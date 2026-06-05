<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ParentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ParentProfile */
class ParentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'phone' => $this->phone,
            'address' => $this->address,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'students_count' => $this->whenCounted('students'),
            'students' => StudentResource::collection($this->whenLoaded('students')),
            'relation' => $this->whenPivotLoaded('parent_student', fn () => $this->pivot->relation),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
