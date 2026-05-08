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
            'description' => $this->description,
            'min_amount' => $this->min_amount !== null ? (float) $this->min_amount : null,
            'max_amount' => $this->max_amount !== null ? (float) $this->max_amount : null,
            'members_count' => 0,
            'contribution_by_tier' => 0,
            'benefits' => $this->benefits ?? [],
            'sort_order' => (int) $this->sort_order,
            'status' => $this->is_active ? 'active' : 'inactive',
            'templates_count' => (int) ($this->templates_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
