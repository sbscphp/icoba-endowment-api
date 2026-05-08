<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class RoleListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['id', 'name', 'users_count', 'updated_at', 'is_active']),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            ]
        );
    }
}
