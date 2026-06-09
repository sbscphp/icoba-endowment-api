<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class ReconciliationQueueListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        $shared = ListingFilterRules::rules(['created_at', 'reconciled_at', 'paid_at', 'amount']);

        return array_merge($shared, [
            'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
            'filters.reconciliation_status' => ['sometimes', 'nullable', Rule::in(['awaiting_payment', 'awaiting_verification', 'unmatched', 'reconciled'])],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'export.in' => "Export format must be either 'csv' or 'pdf'.",
            'filters.reconciliation_status.in' => "Reconciliation status filter must be one of: 'awaiting_payment', 'awaiting_verification', 'unmatched', or 'reconciled'.",
        ]);
    }
}
