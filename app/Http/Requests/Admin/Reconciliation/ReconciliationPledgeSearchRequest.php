<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ReconciliationPledgeSearchRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'user_identity' => ['required', 'uuid', 'exists:giving_identities,uuid'],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(Currency::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'user_identity.required' => 'Please provide the donor giving identity.',
            'user_identity.uuid' => 'user_identity must be a valid giving identity UUID.',
            'user_identity.exists' => 'Selected giving identity does not exist.',
            'campaign_uuid.uuid' => 'campaign_uuid must be a valid campaign UUID.',
            'campaign_uuid.exists' => 'Selected campaign does not exist.',
            'currency.in' => 'Selected currency is invalid.',
        ]);
    }
}
