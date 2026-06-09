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
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(Currency::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'user_identity.required' => 'Please provide the donor giving identity.',
            'user_identity.uuid' => 'user_identity must be a valid giving identity UUID.',
            'user_identity.exists' => 'Selected giving identity does not exist.',
            'currency.in' => 'Selected currency is invalid.',
        ]);
    }
}
