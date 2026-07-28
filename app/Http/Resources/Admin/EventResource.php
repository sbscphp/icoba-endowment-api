<?php

namespace App\Http\Resources\Admin;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('images', 'creator:uuid,name,email', 'updater:uuid,name,email');

        return [
            'event_id' => $this->uuid,
            'public_event_code' => $this->event_id,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'banner_url' => $this->banner_url,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'images' => EventImageResource::collection($this->images)->resolve(),
            'images_count' => $this->images->count(),
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
