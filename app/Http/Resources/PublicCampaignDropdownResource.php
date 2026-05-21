<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class PublicCampaignDropdownResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'campaign_id' => $this->uuid,
            'name' => $this->name,
        ];
    }
}
