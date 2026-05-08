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
            ]
        );
    }
}
