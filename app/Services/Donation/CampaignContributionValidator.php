<?php

namespace App\Services\Donation;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class CampaignContributionValidator
{
    public function assertAcceptingContributions(string $campaignUuid): void
    {
        $campaignUuid = trim($campaignUuid);
        if ($campaignUuid === '') {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Campaign is required.'],
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

        if ($campaign->status === CampaignStatus::PAUSED) {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Donation has been paused.'],
            ]);
        }

        // if ($campaign->status !== CampaignStatus::ACTIVE) {
        //     throw ValidationException::withMessages([
        //         'campaign_uuid' => ['This campaign is not accepting donations.'],
        //     ]);
        // }
    }
}
