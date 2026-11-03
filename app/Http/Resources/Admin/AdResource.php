<?php

namespace App\Http\Resources\Admin;

use App\Enums\AdImageOrientation;
use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class AdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('images', 'creator:uuid,name,email', 'updater:uuid,name,email');

        $landscape = $this->resource->imagesFor(AdImageOrientation::LANDSCAPE);
        $portrait = $this->resource->imagesFor(AdImageOrientation::PORTRAIT);

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
            'images' => AdImageResource::collection($this->images)->resolve(),
            'landscape_images' => AdImageResource::collection($landscape)->resolve(),
            'portrait_images' => AdImageResource::collection($portrait)->resolve(),
            'images_count' => $this->images->count(),
            'landscape_images_count' => $landscape->count(),
            'portrait_images_count' => $portrait->count(),
            'created_by' => $this->creator !== null ? [
                'admin_id' => $this->creator->uuid,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ] : null,
            'updated_by' => $this->updater !== null ? [
                'admin_id' => $this->updater->uuid,
                'name' => $this->updater->name,
                'email' => $this->updater->email,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
