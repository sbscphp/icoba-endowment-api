<?php

namespace App\Http\Resources\Admin;

use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class AdListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ad_id' => $this->uuid,
            'ad_code' => $this->ad_code,
            'title' => $this->title,
            'target_url' => $this->target_url,
            'image_interval_seconds' => (int) $this->image_interval_seconds,
            'starts_at' => $this->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $this->ends_at?->format('Y-m-d H:i:s'),
            'is_active' => (bool) $this->is_active,
            'status' => $this->resource->derivedStatus(),
            'sort_order' => (int) $this->sort_order,
            'images_count' => (int) ($this->resource->images_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
