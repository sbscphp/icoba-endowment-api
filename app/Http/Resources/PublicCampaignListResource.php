<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class PublicCampaignListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $amountRealizedNgn = (float) ($this->amount_realized_ngn ?? 0);
        $donationPledgeNgn = (float) ($this->donation_pledge_ngn ?? 0);
        $target = (float) $this->target_amount;
        $percent = $target > 0 ? round(min(100, ($amountRealizedNgn / $target) * 100), 2) : 0.0;

        return [
            'campaign_id' => $this->uuid,
            'public_campaign_code' => $this->campaign_id,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'cover_image' => $this->cover_image,
            'categories' => is_array($this->categories) ? $this->categories : [],
            'base_currency' => $this->base_currency,
            'available_donation_currencies' => is_array($this->available_donation_currencies)
                ? $this->available_donation_currencies
                : [],
            'target_amount' => (string) $this->target_amount,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'is_default' => (bool) $this->is_default,
            'allow_anonymous_donation' => (bool) $this->allow_anonymous_donation,
            'amount_realized_ngn' => (string) $amountRealizedNgn,
            'donation_pledge_ngn' => (string) $donationPledgeNgn,
            'fund_progress_percent' => $percent,
        ];
    }
}
