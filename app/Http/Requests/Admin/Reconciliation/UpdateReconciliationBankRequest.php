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
}
