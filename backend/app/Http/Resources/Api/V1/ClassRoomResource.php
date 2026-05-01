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
            'section' => $this->section,
            'full_name' => $this->full_name,
            'capacity' => $this->capacity,
            'level' => LevelResource::make($this->whenLoaded('level')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
