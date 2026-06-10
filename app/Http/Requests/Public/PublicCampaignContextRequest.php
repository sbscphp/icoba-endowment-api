<?php

namespace App\Http\Requests\Public;

use App\Http\Requests\ApiFormRequest;

class PublicCampaignContextRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'campaign_uuid' => ['required_without:pledge_uuid', 'prohibits:pledge_uuid', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['required_without:campaign_uuid', 'prohibits:campaign_uuid', 'uuid', 'exists:pledges,uuid'],
        ];
    }
}
