<?php

namespace App\Http\Resources\Admin;

use App\Models\CampaignStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignStatusLog
 */
class CampaignStatusLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('actor:uuid,name,email');

        return [
            'log_id' => $this->uuid,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status instanceof \BackedEnum ? $this->to_status->value : $this->to_status,
            'reason' => $this->reason,
            'metadata' => is_array($this->metadata) ? $this->metadata : [],
            'snapshot_actual_start_date' => $this->snapshot_actual_start_date,
            'snapshot_actual_end_date' => $this->snapshot_actual_end_date,
            'actor' => $this->actor !== null ? [
                'admin_id' => $this->actor->uuid,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
