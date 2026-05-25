<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesCurrencyFromPledge;
use App\Http\Requests\Concerns\ValidatesGuestDonorProfileFields;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StripeGuestCheckoutRequest extends ApiFormRequest
{
    use MergesCurrencyFromPledge;
    use ValidatesGuestDonorProfileFields;

    protected function prepareForValidation(): void
    {
        $this->prepareGuestDonorProfileForValidation();
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
        $this->appendGuestDonorProfileValidation($validator);
    }
}
