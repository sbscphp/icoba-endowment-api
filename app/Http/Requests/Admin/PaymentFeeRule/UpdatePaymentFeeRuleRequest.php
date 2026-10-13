<?php

namespace App\Http\Requests\Admin\PaymentFeeRule;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Models\PaymentFeeRule;
use Illuminate\Validation\Rule;

class UpdatePaymentFeeRuleRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }

    public function rules(): array
    {
        $ruleId = (string) $this->route('ruleId');
        $existing = PaymentFeeRule::query()->where('uuid', $ruleId)->first();

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'currency' => ['sometimes', 'string', Rule::in(Currency::values())],
            'min_amount' => ['sometimes', 'numeric', 'min:0'],
            'max_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                function (string $attribute, mixed $value, \Closure $fail) use ($existing): void {
                    if ($value === null) {
                        return;
                    }
                    $incomingMin = $this->input('min_amount');
                    $effectiveMin = $incomingMin !== null
                        ? (float) $incomingMin
                        : ($existing?->min_amount !== null ? (float) $existing->min_amount : null);
                    if ($effectiveMin !== null && (float) $value <= $effectiveMin) {
                        $fail('Maximum amount must be greater than minimum amount.');
                    }
                },
            ],
            'percentage' => ['sometimes', 'numeric', 'min:0', 'max:99.9999'],
            'fixed_amount' => ['sometimes', 'numeric', 'min:0'],
            'cap_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.max' => 'Rule name may not be longer than 100 characters.',
            'currency.in' => 'Currency is not supported.',
            'min_amount.numeric' => 'Minimum amount must be a number.',
            'min_amount.min' => 'Minimum amount must be at least 0.',
            'max_amount.numeric' => 'Maximum amount must be a number.',
            'percentage.numeric' => 'Percentage must be a number.',
            'percentage.min' => 'Percentage must be at least 0.',
            'percentage.max' => 'Percentage must be below 100.',
            'fixed_amount.numeric' => 'Fixed amount must be a number.',
            'fixed_amount.min' => 'Fixed amount must be at least 0.',
            'cap_amount.numeric' => 'Cap amount must be a number.',
            'cap_amount.min' => 'Cap amount must be at least 0.',
        ]);
    }
}
