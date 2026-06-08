<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesGuestDonorProfileFields;
use App\Services\Bank\BankAccountRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class CreateReconciliationQueueRequest extends ApiFormRequest
{
    use ValidatesGuestDonorProfileFields;

    protected function prepareForValidation(): void
    {
        if (! $this->filled('user_uuid')) {
            $this->prepareGuestDonorProfileForValidation();
        }
    }

    public function rules(): array
    {
        $registry = App::make(BankAccountRegistry::class);
        $accountKeys = $registry->accountKeys();

        $bankFields = [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference_id' => ['required', 'string', 'max:128'],
            'bank_key' => array_merge(
                ['required', 'string', 'max:64'],
                $accountKeys !== [] ? [Rule::in($accountKeys)] : [],
            ),
            'narration' => ['required', 'string', 'max:1000'],
        ];

        $shared = [
            'user_uuid' => ['nullable', 'uuid', 'exists:users,uuid'],
            'campaign_uuid' => ['nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['nullable', 'uuid', 'exists:pledges,uuid'],
            'reconciliation_note' => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['sometimes', 'boolean'],
        ];

        if ($this->filled('user_uuid')) {
            return array_merge($bankFields, $shared, $this->prohibitedDonorProfileRules());
        }

        if ($this->filled('donor_type') || $this->filled('donor_type_uuid')) {
            return array_merge(
                $bankFields,
                $shared,
                $this->guestDonorProfileRulesForSlug($this->resolvedGuestDonorTypeSlug()),
            );
        }

        return array_merge($bankFields, $shared, [
            'donor_type' => ['sometimes', 'nullable', 'string'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'amount.required' => 'Please enter the transfer amount.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.min' => 'Amount must be at least 0.01.',
            'reference_id.required' => 'Please provide the bank statement reference.',
            'reference_id.string' => 'Reference must be a text value.',
            'reference_id.max' => 'Reference may not be longer than 128 characters.',
            'bank_key.required' => 'Please select the ICOBA bank account that received the transfer.',
            'bank_key.string' => 'Bank account must be a text value.',
            'bank_key.max' => 'Bank account may not be longer than 64 characters.',
            'bank_key.in' => 'Selected bank account is not configured. Use an account key from GET reconciliation/bank-accounts.',
            'narration.required' => 'Please provide the bank narration.',
            'narration.string' => 'Narration must be a text value.',
            'narration.max' => 'Narration may not be longer than 1000 characters.',
            'donor_phone.regex' => 'Please enter a valid phone number for the selected country.',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check the graduation year.',
            'donor_type.prohibited' => 'Provide either user_uuid or donor profile fields, not both.',
            'donor_email.prohibited' => 'Provide either user_uuid or donor profile fields, not both.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('user_uuid') && $this->hasDonorProfileInput()) {
                $validator->errors()->add(
                    'user_uuid',
                    'Provide either user_uuid or donor profile fields, not both.',
                );
            }
        });

        if (! $this->filled('user_uuid') && ($this->filled('donor_type') || $this->filled('donor_type_uuid'))) {
            $this->appendGuestDonorProfileValidation($validator);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function prohibitedDonorProfileRules(): array
    {
        $prohibited = ['prohibited'];

        return [
            'donor_type' => $prohibited,
            'donor_type_uuid' => $prohibited,
            'donor_email' => $prohibited,
            'donor_phone' => $prohibited,
            'country_uuid' => $prohibited,
            'country_code' => $prohibited,
            'firstname' => $prohibited,
            'lastname' => $prohibited,
            'set_number' => $prohibited,
            'alumni_identifier' => $prohibited,
            'organization_name' => $prohibited,
            'corporate_category_uuid' => $prohibited,
            'rc_number' => $prohibited,
            'tin' => $prohibited,
        ];
    }

    private function hasDonorProfileInput(): bool
    {
        foreach ([
            'donor_type',
            'donor_type_uuid',
            'donor_email',
            'donor_phone',
            'firstname',
            'lastname',
            'set_number',
            'alumni_identifier',
            'organization_name',
            'corporate_category_uuid',
            'rc_number',
            'tin',
        ] as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }
}
