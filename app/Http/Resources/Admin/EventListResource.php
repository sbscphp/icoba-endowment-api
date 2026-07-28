<?php

namespace App\Http\Resources\Admin;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->uuid,
            'public_event_code' => $this->event_id,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'banner_url' => $this->banner_url,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'images_count' => (int) ($this->resource->images_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
