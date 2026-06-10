<?php

namespace App\Http\Requests\Admin\UserManagement;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class AdminListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['uuid', 'name', 'email', 'last_active_at', 'is_active', 'created_at']),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'export.in' => "Export format must be either 'csv' or 'pdf'.",
            'filters.status.in' => "Status filter must be either 'active' or 'inactive'.",
        ]);
    }
}
