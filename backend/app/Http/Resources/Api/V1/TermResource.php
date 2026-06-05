<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Term
 */
class TermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'school_year_id'   => $this->school_year_id,
            'name'             => $this->name,
            'position'         => $this->position,
            'term_type'        => $this->term_type,
            'applicable_cycle' => $this->applicable_cycle,
            'starts_on'        => $this->starts_on?->toDateString(),
            'ends_on'          => $this->ends_on?->toDateString(),
            'closed_at'        => $this->closed_at,
            'is_closed'        => $this->isClosed(),
            'periods'          => PeriodResource::collection($this->whenLoaded('periods')),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
