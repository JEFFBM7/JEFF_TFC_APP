<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Period;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Period
 */
class PeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term_id' => $this->term_id,
            'school_year_id' => $this->school_year_id,
            'name' => $this->name,
            'position' => $this->position,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'closed_at' => $this->closed_at,
            'is_closed' => $this->isClosed(),
            'term' => TermResource::make($this->whenLoaded('term')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
