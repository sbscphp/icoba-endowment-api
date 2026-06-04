<?php

namespace App\Http\Requests\Admin\Pledge;

use App\Enums\Currency;
use App\Enums\PledgePaymentPlanType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesPledgeScheduleInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class PledgeStoreRequest extends ApiFormRequest
{
    use ValidatesPledgeScheduleInput;

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }

        $this->preparePledgeScheduleForValidation();
    }

    public function rules(): array
    {
        return [
            'campaign_uuid' => ['required', 'uuid', 'exists:campaigns,uuid'],
            'user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'graduation_set_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:sets,uuid'],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'committed_amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
            'exchange_rate_to_naira' => ['prohibited'],
            'payment_plan_type' => ['required', 'string', Rule::in(PledgePaymentPlanType::values())],
            ...$this->pledgeScheduleRules(),
            'with_placeholder_transaction' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendPledgeScheduleValidation($validator);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'campaign_uuid.required' => 'Please select a campaign for this pledge.',
            'campaign_uuid.uuid' => 'Campaign must be referenced by a valid UUID.',
            'campaign_uuid.exists' => 'Selected campaign does not exist.',
            'user_uuid.uuid' => 'User must be referenced by a valid UUID.',
            'user_uuid.exists' => 'Selected user does not exist.',
            'donor_type_uuid.uuid' => 'Donor type must be referenced by a valid UUID.',
            'donor_type_uuid.exists' => 'Selected donor type does not exist.',
            'graduation_set_uuid.uuid' => 'Graduation set must be referenced by a valid UUID.',
            'graduation_set_uuid.exists' => 'Selected graduation set does not exist.',
            'donor_name.max' => 'Donor name may not be longer than 190 characters.',
            'donor_email.email' => 'Please provide a valid donor email address.',
            'donor_email.max' => 'Donor email may not be longer than 190 characters.',
            'donor_phone.max' => 'Donor phone may not be longer than 32 characters.',
            'committed_amount.required' => 'Please specify a committed amount.',
            'committed_amount.numeric' => 'Committed amount must be a number.',
            'committed_amount.min' => 'Committed amount must be at least 0.01.',
            'currency.required' => 'Please select a currency.',
            'currency.in' => 'Selected currency is invalid.',
            'exchange_rate_to_naira.prohibited' => 'Exchange rate to Naira is set automatically and cannot be provided.',
            'payment_plan_type.required' => 'Please select a payment plan type.',
            'payment_plan_type.in' => 'Selected payment plan type is invalid.',
            'metadata.array' => 'Metadata must be a structured object.',
        ]);
    }
}
