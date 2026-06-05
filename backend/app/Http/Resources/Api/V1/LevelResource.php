<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Level */
class LevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'cycle' => $this->cycle,
            'order' => $this->order,
            'has_options' => (bool) $this->has_options,
            'classrooms' => ClassRoomResource::collection($this->whenLoaded('classrooms')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
