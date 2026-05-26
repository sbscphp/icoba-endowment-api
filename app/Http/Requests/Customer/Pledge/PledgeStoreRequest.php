<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Enums\Currency;
use App\Enums\PledgePaymentPlanType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\RequiresResolvableDonorName;
use App\Http\Requests\Concerns\ValidatesGuestDonorProfileFields;
use App\Http\Requests\Concerns\ValidatesPledgeScheduleInput;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class PledgeStoreRequest extends ApiFormRequest
{
    use RequiresResolvableDonorName;
    use ValidatesGuestDonorProfileFields;
    use ValidatesPledgeScheduleInput;

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }

        $this->preparePledgeScheduleForValidation();

        if (! $this->isAuthenticatedMember()) {
            $this->prepareGuestDonorProfileForValidation();
        }
    }

    public function rules(): array
    {
        $pledgeRules = [
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'user_uuid' => ['prohibited'],
            'graduation_set_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:sets,uuid'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'committed_amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
            'exchange_rate_to_naira' => ['prohibited'],
            'payment_plan_type' => ['required', 'string', Rule::in(PledgePaymentPlanType::values())],
            ...$this->pledgeScheduleRules(),
            'with_placeholder_transaction' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];

        if ($this->isAuthenticatedMember()) {
            return array_merge($pledgeRules, $this->memberDonorRules());
        }

        return array_merge(
            $pledgeRules,
            $this->guestDonorProfileRulesForSlug($this->resolvedGuestDonorTypeSlug()),
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'donor_phone.regex' => 'Please enter a valid phone number for the selected country.',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check your graduation year or contact ICOBA support.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendPledgeScheduleValidation($validator);

        if ($this->isAuthenticatedMember()) {
            $this->appendRequiresResolvableDonorNameValidation($validator, true);
        } else {
            $this->appendGuestDonorProfileValidation($validator);
        }
    }

    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function memberDonorRules(): array
    {
        return [
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'donor_name' => ['sometimes', 'string', 'min:2', 'max:190'],
            'organization_name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    private function isAuthenticatedMember(): bool
    {
        return $this->user() instanceof User;
    }
}
