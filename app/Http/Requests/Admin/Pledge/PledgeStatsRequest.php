<?php

namespace App\Http\Requests\Admin\Pledge;

use App\Enums\Currency;
use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class PledgeStatsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $period = strtolower((string) $this->input('period', ''));
        if ($period === '' || $period === 'custom') {
            return;
        }

        $range = ListingFilterRules::dateRangeFromPeriod($period);
        if ($range['start_date'] !== null && $range['end_date'] !== null) {
            $this->merge($range);
        }
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::periodDateRules(),
            [
                'filters' => ['sometimes', 'array'],
                'filters.status' => ['sometimes', 'nullable', Rule::in(PledgeStatus::values())],
                'filters.campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
                'filters.user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
                'filters.currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
                'filters.payment_plan_type' => ['sometimes', 'nullable', Rule::in(PledgePaymentPlanType::values())],
                'filters.is_anonymous' => ['sometimes', 'nullable', Rule::in(['0', '1', 0, 1, true, false, 'true', 'false'])],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::periodDateMessages(), [
            'filters.array' => 'Filters must be a structured set of values.',
            'filters.status.in' => 'Pledge status filter is invalid.',
            'filters.campaign_uuid.uuid' => 'Campaign filter must be a valid UUID.',
            'filters.campaign_uuid.exists' => 'Selected campaign does not exist.',
            'filters.user_uuid.uuid' => 'User filter must be a valid UUID.',
            'filters.user_uuid.exists' => 'Selected user does not exist.',
            'filters.currency.in' => 'Currency filter is invalid.',
            'filters.payment_plan_type.in' => 'Payment plan type filter is invalid.',
            'filters.is_anonymous.in' => 'Anonymous filter must be a boolean value.',
        ]);
    }
}
