<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class ReconciliationQueueListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $shared = ListingFilterRules::rules(['created_at', 'reconciled_at', 'paid_at', 'amount']);

        return array_merge($shared, [
            'filters.reconciliation_status' => ['sometimes', 'nullable', Rule::in(['awaiting_verification', 'unmatched', 'reconciled'])],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.reconciliation_status.in' => "Reconciliation status filter must be one of: 'awaiting_verification', 'unmatched', or 'reconciled'.",
        ]);
    }
}
