<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Enums\CampaignCategory;
use App\Enums\CampaignStatus;
use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class CampaignListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['name', 'start_date', 'end_date', 'target_amount', 'status', 'created_at', 'updated_at']),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.status' => ['sometimes', 'nullable', Rule::in(CampaignStatus::values())],
                'filters.category' => ['sometimes', 'nullable', Rule::in(CampaignCategory::values())],
                'filters.currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
                'filters.graduation_set_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('sets', 'uuid'),
                ],
                'filters.created_by_admin_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('admins', 'uuid'),
                ],
                'filters.date_field' => ['sometimes', 'nullable', Rule::in(['created_at', 'start_date'])],
                'filters.raised_currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
                'filters.min_total_raised' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ]
        );
    }
}
