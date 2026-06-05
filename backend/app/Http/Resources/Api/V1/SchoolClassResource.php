<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_year_id' => $this->school_year_id,
            'level_id' => $this->level_id,
            'school_option_id' => $this->school_option_id,
            'name' => $this->name,
            'active' => $this->active,
            'level' => LevelResource::make($this->whenLoaded('level')),
            'school_option' => SchoolOptionResource::make($this->whenLoaded('schoolOption')),
            'divisions' => ClassRoomResource::collection($this->whenLoaded('divisions')),
            'divisions_count' => (int) ($this->divisions_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
