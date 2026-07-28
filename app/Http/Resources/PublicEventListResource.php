<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class PublicEventListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->uuid,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'banner_url' => $this->banner_url,
        ];
    }
}
