<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\PaymentGateway;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DonationVerifyCheckoutRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('session_id') && ! $this->filled('checkout_session_id')) {
            $this->merge([
                'checkout_session_id' => $this->input('session_id'),
            ]);
        }

        if ($this->filled('reference') && ! $this->filled('checkout_session_id')) {
            $this->merge([
                'checkout_session_id' => $this->input('reference'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'payment_gateway' => ['required', 'string', Rule::enum(PaymentGateway::class)],
            'checkout_session_id' => [
                Rule::requiredIf(fn () => in_array($this->input('payment_gateway'), [
                    PaymentGateway::Stripe->value,
                    PaymentGateway::Paystack->value,
                    PaymentGateway::Fcmb->value,
                ], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transaction_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:transactions,uuid'],
        ];
    }

    public function paymentGateway(): PaymentGateway
    {
        return PaymentGateway::from($this->validated('payment_gateway'));
    }
}
