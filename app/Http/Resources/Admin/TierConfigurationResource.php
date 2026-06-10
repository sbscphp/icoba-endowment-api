<?php

namespace App\Http\Resources;

use App\Models\TierConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TierConfiguration
 */
class TierConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tier_id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'tier_badge_url' => $this->tier_badge_url,
            'base_color' => $this->base_color,
            'min_amount' => $this->min_amount !== null ? (float) $this->min_amount : null,
            'max_amount' => $this->max_amount !== null ? (float) $this->max_amount : null,
            'members_count' => (int) ($this->members_count ?? 0),
            'registered_users_count' => (int) ($this->registered_users_count ?? 0),
            'guest_count' => (int) ($this->guest_count ?? 0),
            'contribution_by_tier' => $this->contribution_by_tier !== null
                ? (string) round((float) $this->contribution_by_tier, 2)
                : '0.00',
            'benefits' => $this->benefits ?? [],
            'sort_order' => (int) $this->sort_order,
            'status' => $this->is_active ? 'active' : 'inactive',
            'templates_count' => (int) ($this->templates_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
