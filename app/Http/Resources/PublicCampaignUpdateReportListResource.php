<?php

namespace App\Http\Resources;

use App\Models\CampaignUpdateReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignUpdateReport
 */
class PublicCampaignUpdateReportListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'report_id' => $this->report_id,
            'report_uuid' => $this->uuid,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'banner_url' => $this->banner_url,
            'youtube_link' => $this->youtube_link,
            'campaign_uuid' => $this->campaign_uuid,
            'campaign_name' => $this->whenLoaded('campaign', fn () => $this->campaign?->name),
            'published_at' => $this->created_at,
        ];
    }
}
