<?php

namespace App\Services\Donation;

use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class CampaignAnonymousDonationValidator
{
    public function assertAllowed(string $campaignUuid, bool $isAnonymous): void
    {
        if (! $isAnonymous) {
            return;
        }

        $campaignUuid = trim($campaignUuid);
        if ($campaignUuid === '') {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Campaign is required to validate anonymous donations.'],
            ]);
        }

        $campaign = Campaign::query()
            ->where('uuid', $campaignUuid)
            ->first();

        if ($campaign === null) {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Selected campaign does not exist.'],
            ]);
        }

        if (! (bool) $campaign->allow_anonymous_donation) {
            throw ValidationException::withMessages([
                'is_anonymous' => ['Anonymous donations are not allowed for this campaign.'],
            ]);
        }
    }
}
