<?php

namespace App\Http\Resources;

use App\Models\CampaignEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignEmail
 */
class BulkEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('campaign:uuid,name,campaign_id', 'creator:uuid,name,email', 'sender:uuid,name,email');

        return [
            'email_id' => $this->uuid,
            'campaign' => $this->campaign !== null ? [
                'campaign_id' => $this->campaign->uuid,
                'public_campaign_code' => $this->campaign->campaign_id,
                'name' => $this->campaign->name,
            ] : null,
            'title' => $this->title,
            'content' => $this->content,
            'design_template' => $this->design_template->value,
            'recipient_audience' => is_array($this->recipient_audience) ? array_values($this->recipient_audience) : [],
            'status' => $this->status->value,
            'is_active' => (bool) $this->is_active,
            'total_recipients' => $this->total_recipients,
            'successful_count' => (int) $this->successful_count,
            'failed_count' => (int) $this->failed_count,
            'created_by' => $this->creator !== null ? [
                'admin_id' => $this->creator->uuid,
                'name' => $this->creator->name,
            ] : null,
            'sent_by' => $this->sender !== null ? [
                'admin_id' => $this->sender->uuid,
                'name' => $this->sender->name,
            ] : null,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
