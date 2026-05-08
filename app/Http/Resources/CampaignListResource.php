<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nairaRaised = $this->resource->successful_contributions_sum_naira ?? null;
        $raisedCurrency = strtoupper((string) $request->input('filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }
        $filteredRaised = $this->resource->total_raised_filtered ?? null;

        return [
            'campaign_id' => $this->uuid,
            'public_campaign_code' => $this->campaign_id,
            'name' => $this->name,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'actual_start_date' => $this->actual_start_date,
            'actual_end_date' => $this->actual_end_date,
            'target_amount' => (string) $this->target_amount,
            'base_currency' => $this->base_currency,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'total_raised' => $filteredRaised !== null ? (string) $filteredRaised : '0',
            'total_raised_currency' => $raisedCurrency,
            'total_raised_in_naira' => $nairaRaised !== null ? (string) $nairaRaised : '0',
            'last_updated' => $this->updated_at,
        ];
    }
}
