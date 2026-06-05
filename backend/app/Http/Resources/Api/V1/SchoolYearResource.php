<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SchoolYear;
use App\Support\AdminScopeContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolYear
 */
class SchoolYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_current' => $this->is_current,
            'is_archived' => $this->isArchived(),
            'closed_at' => $this->closed_at,
            'archived_at' => $this->archived_at,
            'archived_by' => $this->whenLoaded('archivedBy', fn () => $this->archivedBy ? [
                'id' => $this->archivedBy->id,
                'name' => $this->archivedBy->name,
                'email' => $this->archivedBy->email,
            ] : null),
            'school_classes_count' => $this->whenCounted('schoolClasses'),
            'terms' => $this->whenLoaded('terms', function () use ($request) {
                return TermResource::collection(
                    AdminScopeContext::filterTermsForUser($this->terms, $request->user()),
                );
            }),
            'stats' => $this->when(
                $this->resource->getAttribute('stats') !== null,
                $this->resource->getAttribute('stats'),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
