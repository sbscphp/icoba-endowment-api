<?php

namespace App\Http\Resources;

use App\Models\HeroSlide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeroSlide
 */
class HeroSlideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slide_id' => $this->uuid,
            'title' => $this->title,
            'banner_url' => $this->banner_url,
            // 'primary_cta_url' => $this->primary_cta_url,
            // 'primary_cta_text' => $this->primary_cta_text,
            // 'secondary_cta_url' => $this->secondary_cta_url,
            // 'secondary_cta_text' => $this->secondary_cta_text,
            // 'sort_order' => (int) $this->sort_order,
            // 'status' => $this->is_active ? 'active' : 'inactive',
            // 'is_deletable' => (bool) $this->is_deletable,
            // 'updated_by' => $this->whenLoaded('updatedByAdmin', fn () => $this->updatedByAdmin?->displayName()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
