<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * @mixin Event
 */
class PublicEventDetailResource extends PublicEventListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('images');

        return array_merge(parent::toArray($request), [
            'long_description' => $this->long_description,
            'images' => $this->images->map(fn ($image): array => [
                'image_id' => $image->uuid,
                'image_url' => $image->image_url,
            ])->values()->all(),
        ]);
    }
}
