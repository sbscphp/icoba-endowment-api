<?php

namespace App\Http\Requests\Admin\PaymentFeeRule;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentFeeRuleRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
        if (is_string($this->input('cap_currency'))) {
            $this->merge(['cap_currency' => strtoupper(trim((string) $this->input('cap_currency')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
            'min_amount' => ['sometimes', 'numeric', 'min:0'],
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'gt:min_amount'],
            'percentage' => ['sometimes', 'numeric', 'min:0', 'max:99.9999'],
            'fixed_amount' => ['sometimes', 'numeric', 'min:0'],
            'cap_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Currency the cap is written in (e.g. NGN on a USD rule); defaults to the rule currency.
            'cap_currency' => ['sometimes', 'nullable', 'string', Rule::in(Currency::values())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please give this fee rule a name.',
            'name.max' => 'Rule name may not be longer than 100 characters.',
            'currency.required' => 'Please specify the currency this rule applies to.',
            'currency.in' => 'Currency is not supported.',
            'min_amount.numeric' => 'Minimum amount must be a number.',
            'min_amount.min' => 'Minimum amount must be at least 0.',
            'max_amount.numeric' => 'Maximum amount must be a number.',
            'max_amount.gt' => 'Maximum amount must be greater than minimum amount.',
            'percentage.numeric' => 'Percentage must be a number.',
            'percentage.min' => 'Percentage must be at least 0.',
            'percentage.max' => 'Percentage must be below 100.',
            'fixed_amount.numeric' => 'Fixed amount must be a number.',
            'fixed_amount.min' => 'Fixed amount must be at least 0.',
            'cap_amount.numeric' => 'Cap amount must be a number.',
            'cap_amount.min' => 'Cap amount must be at least 0.',
            'cap_currency.in' => 'Cap currency is not supported.',
        ]);
    }
}
