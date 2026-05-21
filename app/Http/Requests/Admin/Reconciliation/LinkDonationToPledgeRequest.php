<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;

class LinkDonationToPledgeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'payment_transaction_uuid' => ['required', 'uuid', 'exists:transactions,uuid'],
            'pledge_uuid' => ['required', 'uuid', 'exists:pledges,uuid'],
            'supersede_transaction_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:transactions,uuid'],
        ];
    }
}
