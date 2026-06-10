<?php

namespace App\Http\Resources\Admin;

use App\Models\CampaignEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignEmail
 */
class BulkEmailListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(
            'campaign:uuid,name,campaign_id',
            'creator:uuid,name,email',
            'sender:uuid,name,email',
        );

        return [
            'email_id' => $this->uuid,
            'title' => $this->title,
            'linked_campaign' => $this->campaign !== null ? $this->campaign->name : null,
            'public_campaign_code' => $this->campaign?->campaign_id,
            'status' => $this->status->value,
            'is_active' => (bool) $this->is_active,
            'recipient_audience' => is_array($this->recipient_audience) ? array_values($this->recipient_audience) : [],
            'created_by' => $this->creator !== null ? [
                'admin_id' => $this->creator->uuid,
                'name' => $this->creator->name,
            ] : null,
            'sent_by' => $this->sender !== null ? [
                'admin_id' => $this->sender->uuid,
                'name' => $this->sender->name,
            ] : null,
            'sent_at' => $this->sent_at,
            'last_updated' => $this->updated_at,
        ];
    }
}
