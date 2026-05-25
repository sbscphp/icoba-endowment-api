<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesCurrencyFromPledge;
use App\Http\Requests\Concerns\ValidatesGuestDonorProfileFields;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StripeCheckoutRequest extends ApiFormRequest
{
    use MergesCurrencyFromPledge {
        prepareForValidation as mergeCurrencyFromPledge;
    }
    use ValidatesGuestDonorProfileFields;

    protected function prepareForValidation(): void
    {
        $this->mergeCurrencyFromPledge();

        if (! $this->isAuthenticatedMember()) {
            $this->prepareGuestDonorProfileForValidation();
        }
    }

    public function rules(): array
    {
        $checkoutRules = [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => [
                Rule::requiredIf(fn () => ! $this->filled('pledge_uuid')),
                'nullable',
                'string',
                Rule::in(Currency::values()),
            ],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:pledges,uuid'],
            'user_uuid' => ['prohibited'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'exchange_rate_to_naira' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'application_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'frontend_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'success_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'cancel_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];

        if ($this->isAuthenticatedMember()) {
            return array_merge($checkoutRules, $this->memberDonorRules());
        }

        return array_merge(
            $checkoutRules,
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
        if (! $this->isAuthenticatedMember()) {
            $this->appendGuestDonorProfileValidation($validator);
        }
    }

    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function memberDonorRules(): array
    {
        return [
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
        ];
    }

    private function isAuthenticatedMember(): bool
    {
        return $this->user() instanceof User;
    }
}
