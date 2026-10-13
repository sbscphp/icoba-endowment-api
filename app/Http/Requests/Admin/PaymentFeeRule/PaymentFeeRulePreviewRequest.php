<?php

namespace App\Http\Requests\Admin\PaymentFeeRule;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PaymentFeeRulePreviewRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
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
            'amount.required' => 'Please provide an amount to preview.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.min' => 'Amount must be at least 0.01.',
            'currency.required' => 'Please provide a currency.',
            'currency.in' => 'Currency is not supported.',
        ]);
    }
}
