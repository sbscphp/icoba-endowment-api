<?php

namespace App\Http\Resources\Admin;

use App\Models\AdImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdImage
 */
class AdImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'image_id' => $this->uuid,
            'image_url' => $this->image_url,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
