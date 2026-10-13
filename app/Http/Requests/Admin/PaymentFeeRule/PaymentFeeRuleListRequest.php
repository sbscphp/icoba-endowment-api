<?php

namespace App\Http\Requests\Admin\PaymentFeeRule;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class PaymentFeeRuleListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);

        $filters = is_array($this->input('filters')) ? $this->input('filters') : [];
        if (is_string($filters['currency'] ?? null)) {
            $filters['currency'] = strtoupper(trim($filters['currency']));
            $this->merge(['filters' => $filters]);
        }
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['name', 'currency', 'min_amount', 'max_amount', 'percentage', 'is_active', 'created_at', 'updated_at']),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
                'filters.currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'export.in' => "Export format must be either 'csv' or 'pdf'.",
            'filters.status.in' => "Status filter must be either 'active' or 'inactive'.",
            'filters.currency.in' => 'Currency filter is not supported.',
        ]);
    }
}
