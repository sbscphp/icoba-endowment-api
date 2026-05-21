<?php

namespace App\Http\Requests\Customer\Settings;

use App\Enums\DonorTypeSlug;
use App\Http\Requests\ApiFormRequest;
use App\Models\Country;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use App\Support\CustomerProfileUpdateFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class CustomerProfileUpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $slug = $this->customerDonorTypeSlug();

        return array_merge(
            CustomerProfileUpdateFields::prohibitedRules($slug),
            $this->allowedFieldRules($slug),
        );
    }

    public function messages(): array
    {
        $messages = [
            'email.prohibited' => 'Email cannot be changed.',
            'donor_type.prohibited' => 'Donor type cannot be changed.',
            'donor_type_uuid.prohibited' => 'Donor type cannot be changed.',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check your graduation year or contact ICOBA support.',
            'phone_number.regex' => 'Please enter a valid phone number for the selected country.',
        ];

        foreach (CustomerProfileUpdateFields::prohibitedKeys($this->customerDonorTypeSlug()) as $field) {
            if (! isset($messages["{$field}.prohibited"])) {
                $messages["{$field}.prohibited"] = 'This field is not allowed for your account type.';
            }
        }

        return array_merge(parent::messages(), $messages);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        if ($this->has('donor_type')) {
            $this->merge(['donor_type' => strtolower(trim((string) $this->input('donor_type')))]);
        }

        if (! $this->has('phone_number')) {
            return;
        }

        $country = Country::forRegistration($this->input('country_uuid'));

        $normalized = app(PhoneNumberService::class)->normalize(
            (string) $this->input('phone_number'),
            $country,
        );

        if ($normalized !== null) {
            $this->merge($normalized);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('phone_number')) {
                return;
            }

            $user = $this->user();
            if (! $user instanceof User) {
                return;
            }

            $phoneNumber = (string) $this->input('phone_number');
            $forms = app(PhoneNumberService::class)->equivalentStoredValues($phoneNumber);

            if ($forms === []) {
                return;
            }

            $taken = User::query()
                ->where('uuid', '!=', $user->uuid)
                ->whereIn('phone_number', $forms)
                ->exists();

            if ($taken) {
                $validator->errors()->add(
                    'phone_number',
                    'An account with this phone number already exists. Would you like to log in instead, or use a different phone number?',
                );
            }
        });
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    private function allowedFieldRules(?string $slug): array
    {
        $contact = [
            'phone_number' => ['sometimes', 'string', 'regex:/^\+\d{8,15}$/'],
            'country_uuid' => ['sometimes', 'nullable', 'uuid', Rule::exists('countries', 'uuid')->where('is_active', true)],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($contact, [
                'firstname' => $this->personNameRules(),
                'lastname' => $this->personNameRules(),
                'middlename' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u'],
                'set_number' => ['sometimes', 'string', 'max:16', Rule::exists('sets', 'set_number')],
                'alumni_identifier' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9]*$/'],
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($contact, [
                'organization_name' => ['sometimes', 'string', 'min:2', 'max:100'],
                'corporate_category_uuid' => ['sometimes', 'uuid', Rule::exists('corporate_categories', 'uuid')],
                'rc_number' => ['sometimes', 'string', 'min:2', 'max:64'],
                'tin' => ['sometimes', 'string', 'min:2', 'max:64'],
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($contact, [
                'firstname' => $this->personNameRules(),
                'lastname' => $this->personNameRules(),
                'middlename' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u'],
            ]),
            default => $contact,
        };
    }

    /**
     * @return list<string|ValidationRule>
     */
    private function personNameRules(): array
    {
        return [
            'sometimes',
            'string',
            'min:2',
            'max:50',
            'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u',
        ];
    }

    private function customerDonorTypeSlug(): ?string
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return null;
        }

        $user->loadMissing('donorType');

        return $user->donorType?->slug;
    }
}
