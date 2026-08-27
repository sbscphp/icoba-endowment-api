<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class AdListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['title', 'starts_at', 'ends_at', 'is_active', 'sort_order', 'created_at', 'updated_at']),
            [
                'filters.is_active' => ['sometimes', 'nullable', 'boolean'],
                'filters.status' => ['sometimes', 'nullable', Rule::in(['live', 'scheduled', 'expired', 'archived'])],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.is_active.boolean' => 'Active status filter must be true or false.',
            'filters.status.in' => 'Ad status filter is invalid.',
        ]);
    }
}
