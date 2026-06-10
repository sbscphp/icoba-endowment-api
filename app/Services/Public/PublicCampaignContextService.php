<?php

namespace App\Services\Public;

use App\Models\Campaign;
use App\Models\Pledge;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicCampaignContextService
{
    public function resolve(?string $campaignUuid, ?string $pledgeUuid): Campaign
    {
        if (is_string($pledgeUuid) && $pledgeUuid !== '') {
            $pledge = Pledge::query()
                ->with(['campaign' => fn ($query) => $query->select('uuid', 'name', 'status', 'allow_anonymous_donation')])
                ->where('uuid', $pledgeUuid)
                ->firstOrFail();

            if ($pledge->campaign === null) {
                throw (new ModelNotFoundException)->setModel(Campaign::class);
            }

            return $pledge->campaign;
        }

        return Campaign::query()
            ->where('uuid', $campaignUuid)
            ->firstOrFail(['uuid', 'name', 'status', 'allow_anonymous_donation']);
    }
}
