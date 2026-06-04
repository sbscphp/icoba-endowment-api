<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use App\Services\Bank\BankAccountRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class UpdateReconciliationBankRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $registry = App::make(BankAccountRegistry::class);
        $numbers = $registry->accountNumbers();
        $keys = $registry->accountKeys();

        return [
            'paid_into_account_number' => array_merge(
                ['nullable', 'required_without:paid_into_account_key', 'string', 'max:64'],
                $numbers !== [] ? [Rule::in($numbers)] : [],
            ),
            'paid_into_account_key' => array_merge(
                ['nullable', 'required_without:paid_into_account_number', 'string', 'max:64'],
                $keys !== [] ? [Rule::in($keys)] : [],
            ),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->filled('paid_into_account_number') && ! $this->filled('paid_into_account_key')) {
                $validator->errors()->add(
                    'paid_into_account_number',
                    'Provide either paid_into_account_number or paid_into_account_key.',
                );
            }
        });
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'paid_into_account_number.required_without' => 'Provide either an account number or an account key.',
            'paid_into_account_number.string' => 'Account number must be a text value.',
            'paid_into_account_number.max' => 'Account number may not be longer than 64 characters.',
            'paid_into_account_number.in' => 'Selected account number is not configured.',
            'paid_into_account_key.required_without' => 'Provide either an account key or an account number.',
            'paid_into_account_key.string' => 'Account key must be a text value.',
            'paid_into_account_key.max' => 'Account key may not be longer than 64 characters.',
            'paid_into_account_key.in' => 'Selected account key is not configured.',
        ]);
    }
}
