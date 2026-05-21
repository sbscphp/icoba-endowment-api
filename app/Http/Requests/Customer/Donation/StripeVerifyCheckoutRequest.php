<?php

namespace App\Http\Requests\Customer\Donation;

use App\Http\Requests\ApiFormRequest;

class StripeVerifyCheckoutRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('session_id') && ! $this->filled('checkout_session_id')) {
            $this->merge([
                'checkout_session_id' => $this->input('session_id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'checkout_session_id' => ['required', 'string', 'max:255'],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transaction_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:transactions,uuid'],
        ];
    }
}
