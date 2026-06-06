<?php

namespace App\Http\Requests\Admin\Pledge;

use App\Enums\PledgeStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class PledgeListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules([
                'created_at',
                'updated_at',
            ]),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(PledgeStatus::values())],
                'filters.campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
                'filters.user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.status.in' => 'Pledge status filter is invalid.',
            'filters.campaign_uuid.uuid' => 'Campaign filter must be a valid UUID.',
            'filters.campaign_uuid.exists' => 'Selected campaign does not exist.',
            'filters.user_uuid.uuid' => 'User filter must be a valid UUID.',
            'filters.user_uuid.exists' => 'Selected user does not exist.',
        ]);
    }
}
