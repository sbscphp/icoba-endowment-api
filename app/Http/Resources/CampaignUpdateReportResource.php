<?php

namespace App\Http\Resources;

use App\Enums\CampaignUpdateReportStatus;
use App\Models\CampaignUpdateReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignUpdateReport
 */
class CampaignUpdateReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'report_id' => $this->report_id,
            'report_uuid' => $this->uuid,
            'campaign_uuid' => $this->campaign_uuid,
            'campaign_name' => $this->whenLoaded('campaign', fn () => $this->campaign?->name),
            'name' => $this->name,
            'short_description' => $this->short_description,
            'details' => $this->details,
            'banner_url' => $this->banner_url,
            'youtube_link' => $this->youtube_link,
            'status' => CampaignUpdateReportStatus::fromIsActive((bool) $this->is_active)->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
