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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'payment_transaction_uuid.required' => 'Please select the payment transaction to link.',
            'payment_transaction_uuid.uuid' => 'Payment transaction must be referenced by a valid UUID.',
            'payment_transaction_uuid.exists' => 'Selected payment transaction does not exist.',
            'pledge_uuid.required' => 'Please select the pledge to link this donation to.',
            'pledge_uuid.uuid' => 'Pledge must be referenced by a valid UUID.',
            'pledge_uuid.exists' => 'Selected pledge does not exist.',
            'supersede_transaction_uuid.uuid' => 'Superseded transaction must be referenced by a valid UUID.',
            'supersede_transaction_uuid.exists' => 'Superseded transaction does not exist.',
        ]);
    }
}
