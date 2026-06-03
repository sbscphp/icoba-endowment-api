<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use App\Models\GraduationSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('creator:uuid,name,email');
        if (! $this->applies_to_all_graduation_sets) {
            $this->resource->loadMissing('graduationSets:uuid,name,set_number');
        }

        $raisedCurrency = strtoupper((string) $request->input('filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }
        $nairaRaised = $this->resource->successful_contributions_sum_naira ?? null;
        $filteredRaised = $this->resource->total_raised_filtered ?? null;

        return [
            'campaign_id' => $this->uuid,
            'public_campaign_code' => $this->campaign_id,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'cover_image' => $this->cover_image,
            'gallery_images' => is_array($this->gallery_images) ? $this->gallery_images : [],
            'categories' => is_array($this->categories) ? $this->categories : [],
            'base_currency' => $this->base_currency,
            'available_donation_currencies' => is_array($this->available_donation_currencies) ? $this->available_donation_currencies : [],
            'target_amount' => (string) $this->target_amount,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'actual_start_date' => $this->actual_start_date,
            'actual_end_date' => $this->actual_end_date,
            'allow_anonymous_donation' => (bool) $this->allow_anonymous_donation,
            'allow_public_donation' => (bool) $this->allow_public_donation,
            'applies_to_all_graduation_sets' => (bool) $this->applies_to_all_graduation_sets,
            'graduation_sets' => $this->graduationSetsPayload(),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'total_raised' => $filteredRaised !== null ? (string) $filteredRaised : '0',
            'total_raised_currency' => $raisedCurrency,
            'total_raised_in_naira' => $nairaRaised !== null ? (string) $nairaRaised : '0',
            'created_by' => $this->creator !== null ? [
                'admin_id' => $this->creator->uuid,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return list<array{graduation_set_id: string, name: string, set_number: string}>
     */
    private function graduationSetsPayload(): array
    {
        if ($this->applies_to_all_graduation_sets) {
            return GraduationSet::query()
                ->orderBy('name')
                ->get(['uuid', 'name', 'set_number'])
                ->map(fn (GraduationSet $set): array => [
                    'graduation_set_id' => $set->uuid,
                    'name' => $set->name,
                    'set_number' => $set->set_number,
                ])
                ->values()
                ->all();
        }

        return $this->graduationSets->map(fn (GraduationSet $set): array => [
            'graduation_set_id' => $set->uuid,
            'name' => $set->name,
            'set_number' => $set->set_number,
        ])->values()->all();
    }
}
