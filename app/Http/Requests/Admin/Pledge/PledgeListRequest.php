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
}
