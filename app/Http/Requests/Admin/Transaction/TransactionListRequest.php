<?php

namespace App\Http\Requests\Admin\Transaction;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class TransactionListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules([
                'transaction_id',
                'donor_name',
                'amount',
                'amount_in_naira',
                'status',
                'paid_at',
                'created_at',
                'updated_at',
            ]),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.status' => ['sometimes', 'nullable', Rule::in(TransactionStatus::values())],
                'filters.currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
                'filters.gateway' => ['sometimes', 'nullable', 'string', 'max:64'],
                'filters.campaign_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('campaigns', 'uuid'),
                ],
                'filters.user_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('users', 'uuid'),
                ],
                'filters.graduation_set_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('sets', 'uuid'),
                ],
                'filters.is_anonymous' => ['sometimes', 'nullable', Rule::in(['0', '1', 0, 1, true, false, 'true', 'false'])],
                'filters.min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'filters.max_amount' => ['sometimes', 'nullable', 'numeric', 'gte:filters.min_amount'],
                'filters.date_field' => ['sometimes', 'nullable', Rule::in(['created_at', 'paid_at'])],
                'filters.pledge_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:pledges,uuid'],
                'filters.include_superseded' => ['sometimes', 'boolean'],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'export.in' => "Export format must be either 'csv' or 'pdf'.",
            'filters.status.in' => 'Transaction status filter is invalid.',
            'filters.currency.in' => 'Currency filter is invalid.',
            'filters.gateway.max' => 'Gateway filter may not be longer than 64 characters.',
            'filters.campaign_uuid.exists' => 'Selected campaign does not exist.',
            'filters.user_uuid.exists' => 'Selected user does not exist.',
            'filters.graduation_set_uuid.exists' => 'Selected graduation set does not exist.',
            'filters.is_anonymous.in' => 'Anonymous filter must be a boolean value.',
            'filters.min_amount.numeric' => 'Minimum amount filter must be a number.',
            'filters.min_amount.min' => 'Minimum amount filter must be at least 0.',
            'filters.max_amount.numeric' => 'Maximum amount filter must be a number.',
            'filters.max_amount.gte' => 'Maximum amount filter must be greater than or equal to the minimum amount.',
            'filters.date_field.in' => "Date field filter must be either 'created_at' or 'paid_at'.",
            'filters.pledge_uuid.uuid' => 'Pledge filter must be a valid UUID.',
            'filters.pledge_uuid.exists' => 'Selected pledge does not exist.',
            'filters.include_superseded.boolean' => 'Include superseded filter must be true or false.',
        ]);
    }
}
