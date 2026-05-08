<?php

namespace App\Http\Requests\Admin;

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
                'filters.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            ]
        );
    }
}
