<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;

class CampaignStatsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $period = strtolower((string) $this->input('period', ''));
        if ($period === '' || $period === 'custom') {
            return;
        }

        $range = ListingFilterRules::dateRangeFromPeriod($period);
        if ($range['start_date'] !== null && $range['end_date'] !== null) {
            $this->merge($range);
        }
    }

    public function rules(): array
    {
        return ListingFilterRules::periodDateRules();
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::periodDateMessages());
    }
}
