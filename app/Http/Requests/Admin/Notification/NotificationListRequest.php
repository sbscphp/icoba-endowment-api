<?php

namespace App\Http\Requests\Admin\Notification;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class NotificationListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::prepareListingRequest($this);
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::periodDateRules(),
            [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'filters' => ['sometimes', 'array'],
                'filters.read_status' => ['sometimes', 'nullable', Rule::in(['all', 'read', 'unread'])],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::periodDateMessages(), [
            'filters.read_status.in' => 'Read status filter must be one of: all, read, unread.',
            'page.integer' => 'Page must be a number.',
            'page.min' => 'Page must be at least 1.',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not be greater than 100.',
        ]);
    }
}
