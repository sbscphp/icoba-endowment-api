<?php

namespace App\Http\Resources;

use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class PublicAdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ad_id' => $this->uuid,
            'title' => $this->title,
            'target_url' => $this->target_url,
            'image_interval_seconds' => (int) $this->image_interval_seconds,
            'images' => $this->images->pluck('image_url')->values()->all(),
        ];
    }
}
