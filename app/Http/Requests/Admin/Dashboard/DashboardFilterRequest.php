<?php

namespace App\Http\Requests\Admin\Dashboard;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;

class DashboardFilterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ListingFilterRules::periodDateRules(includeCurrency: true);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::periodDateMessages());
    }

    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);

        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper((string) $this->input('currency')),
            ]);
        }
    }
}
