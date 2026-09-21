<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Services\Admin\ContentManagement\FaqService;

class FaqListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(FaqService::SORTABLE_COLUMNS),
            [
                'filters.is_active' => ['sometimes', 'nullable', 'boolean'],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.is_active.boolean' => 'Active status filter must be true or false.',
        ]);
    }
}
