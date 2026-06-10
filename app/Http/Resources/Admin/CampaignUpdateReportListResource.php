<?php

namespace App\Http\Resources;

use App\Enums\CampaignUpdateReportStatus;
use App\Models\CampaignUpdateReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignUpdateReport
 */
class CampaignUpdateReportListResource extends JsonResource
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'status' => CampaignUpdateReportStatus::fromIsActive((bool) $this->is_active)->value,
        ];
    }
}
