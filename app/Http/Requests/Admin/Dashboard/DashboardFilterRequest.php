<?php

namespace App\Http\Requests\Admin\Dashboard;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;

/**
 * Query filters for admin dashboard routes (overview, trends, donation breakdowns, etc.).
 *
 * Optional `currency` (NGN|USD|GBP|EUR):
 * - Not sent, or sent empty (`?currency` / `?currency=`): treated as no currency filter.
 *   {@see \App\Services\Admin\Dashboard\DashboardService} aggregates all successful
 *   transactions using `amount_in_naira`.
 * - Sent with a value: restricts metrics to that `transactions.currency` and sums `amount`.
 */
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

        // Empty currency must not land in validated() — otherwise DashboardService would
        // treat it as an active filter. Non-empty values are uppercased for Rule::in().
        if ($this->has('currency')) {
            $raw = trim((string) $this->input('currency'));
            if ($raw === '') {
                $this->replace($this->except('currency'));
            } else {
                $this->merge([
                    'currency' => strtoupper($raw),
                ]);
            }
        }
    }
}
