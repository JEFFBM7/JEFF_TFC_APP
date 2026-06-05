<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SchoolOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SchoolOption */
class SchoolOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'filiere' => $this->filiere,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
