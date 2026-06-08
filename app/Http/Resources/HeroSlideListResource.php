<?php

namespace App\Http\Resources;

use App\Models\HeroSlide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeroSlide
 */
class HeroSlideListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slide_id' => $this->uuid,
            'title' => $this->title,
            'sort_order' => (int) $this->sort_order,
            'status' => $this->is_active ? 'active' : 'inactive',
            'is_deletable' => (bool) $this->is_deletable,
            'updated_by' => $this->whenLoaded('updatedByAdmin', fn () => $this->updatedByAdmin?->displayName()),
            'last_updated' => $this->updated_at,
        ];
    }
}
