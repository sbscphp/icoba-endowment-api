<?php

namespace App\Http\Requests\Admin\Pledge;

use App\Enums\Currency;
use App\Enums\PledgePaymentPlanType;
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
        $boolean = ['0', '1', 0, 1, true, false, 'true', 'false'];

        return array_merge(
            ListingFilterRules::rules([
                'donor_name',
                'committed_amount',
                'committed_amount_ngn',
                'status',
                'created_at',
                'updated_at',
            ]),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(PledgeStatus::values())],
                'filters.campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
                'filters.user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
                'filters.currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
                'filters.payment_plan_type' => ['sometimes', 'nullable', Rule::in(PledgePaymentPlanType::values())],
                'filters.is_anonymous' => ['sometimes', 'nullable', Rule::in($boolean)],
                'filters.has_user_account' => ['sometimes', 'nullable', Rule::in($boolean)],
                'filters.graduation_set_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:sets,uuid'],
                'filters.donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
                'filters.min_committed_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'filters.max_committed_amount' => ['sometimes', 'nullable', 'numeric', 'gte:filters.min_committed_amount'],
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
            'filters.currency.in' => 'Currency filter is invalid.',
            'filters.payment_plan_type.in' => 'Payment plan type filter is invalid.',
            'filters.is_anonymous.in' => 'Anonymous filter must be a boolean value.',
            'filters.has_user_account.in' => 'User account filter must be a boolean value.',
            'filters.graduation_set_uuid.uuid' => 'Graduation set filter must be a valid UUID.',
            'filters.graduation_set_uuid.exists' => 'Selected graduation set does not exist.',
            'filters.donor_type_uuid.uuid' => 'Donor type filter must be a valid UUID.',
            'filters.donor_type_uuid.exists' => 'Selected donor type does not exist.',
            'filters.min_committed_amount.numeric' => 'Minimum committed amount must be a number.',
            'filters.min_committed_amount.min' => 'Minimum committed amount cannot be negative.',
            'filters.max_committed_amount.numeric' => 'Maximum committed amount must be a number.',
            'filters.max_committed_amount.gte' => 'Maximum committed amount must be greater than or equal to the minimum.',
        ]);
    }
}
