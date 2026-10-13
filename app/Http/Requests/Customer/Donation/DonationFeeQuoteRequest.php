<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DonationFeeQuoteRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');
        if (is_string($currency) && $currency !== '') {
            $this->merge(['currency' => strtoupper(trim($currency))]);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'amount.required' => 'Please provide the donation amount.',
            'amount.numeric' => 'Donation amount must be a number.',
            'amount.min' => 'Donation amount must be at least 0.01.',
            'currency.required' => 'Please provide the donation currency.',
            'currency.in' => 'Currency is not supported.',
        ]);
    }
}
