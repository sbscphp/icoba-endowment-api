<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DonorTypeSlug;
use App\Models\Country;
use App\Models\DonorType;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesGuestDonorProfileFields
{
    protected function prepareGuestDonorProfileForValidation(): void
    {
        if ($this->filled('donor_type_uuid') && ! $this->filled('donor_type')) {
            $slug = DonorType::query()
                ->where('uuid', (string) $this->input('donor_type_uuid'))
                ->value('slug');

            if (is_string($slug) && $slug !== '') {
                $this->merge(['donor_type' => $slug]);
            }
        }

        $this->merge([
            'donor_email' => strtolower(trim((string) $this->input('donor_email'))),
            'donor_type' => strtolower(trim((string) $this->input('donor_type'))),
        ]);

        $country = Country::forRegistration($this->input('country_uuid'));
        $normalized = app(PhoneNumberService::class)->normalize(
            (string) $this->input('donor_phone', ''),
            $country,
        );

        if ($normalized !== null) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    protected function guestDonorIdentityRules(): array
    {
        return [
            'donor_type' => ['required_without:donor_type_uuid', 'nullable', 'string', Rule::in(DonorTypeSlug::values()), Rule::exists('donor_types', 'slug')],
            'donor_type_uuid' => ['required_without:donor_type', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'donor_email' => ['required', 'email', 'max:190'],
            'donor_phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'country_uuid' => ['nullable', 'uuid', Rule::exists('countries', 'uuid')->where('is_active', true)],
            'country_code' => ['nullable', 'string', 'max:10'],
            'donor_name' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    protected function guestDonorProfileRulesForSlug(?string $slug): array
    {
        $shared = $this->guestDonorIdentityRules();

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($shared, [
                'firstname' => $this->guestPersonNameRules(),
                'lastname' => $this->guestPersonNameRules(),
                'set_number' => ['required', 'string', 'max:16', Rule::exists('sets', 'set_number')],
                'alumni_identifier' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9]*$/'],
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($shared, [
                'organization_name' => ['required', 'string', 'min:2', 'max:150'],
                'corporate_category_uuid' => ['required', 'uuid', Rule::exists('corporate_categories', 'uuid')],
                'rc_number' => ['required', 'string', 'min:2', 'max:64'],
                'tin' => ['required', 'string', 'min:2', 'max:64'],
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($shared, [
                'firstname' => $this->guestPersonNameRules(),
                'lastname' => $this->guestPersonNameRules(),
            ]),
            default => $shared,
        };
    }

    /**
     * @return list<string|ValidationRule>
     */
    private function guestPersonNameRules(): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:50',
            'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u',
        ];
    }

    protected function resolvedGuestDonorTypeSlug(): ?string
    {
        $slug = strtolower(trim((string) $this->input('donor_type', '')));
        if ($slug !== '') {
            return $slug;
        }

        $donorTypeUuid = $this->input('donor_type_uuid');
        if (! is_string($donorTypeUuid) || $donorTypeUuid === '') {
            return null;
        }

        $fromUuid = DonorType::query()->where('uuid', $donorTypeUuid)->value('slug');

        return is_string($fromUuid) && $fromUuid !== '' ? $fromUuid : null;
    }

    protected function appendGuestDonorProfileValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $phoneNumber = (string) $this->input('donor_phone', '');
            if ($phoneNumber === '') {
                return;
            }

            $country = Country::forRegistration($this->input('country_uuid'));
            if (app(PhoneNumberService::class)->normalize($phoneNumber, $country) === null) {
                $validator->errors()->add(
                    'donor_phone',
                    'Please enter a valid phone number for the selected country.',
                );
            }
        });

        $this->appendNewDonorUniquenessValidation($validator);
    }

    protected function appendNewDonorUniquenessValidation(Validator $validator): void
    {
        if ($this->filled('user_uuid')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->filled('donor_type') && ! $this->filled('donor_type_uuid')) {
                return;
            }

            $email = strtolower(trim((string) $this->input('donor_email', '')));
            if ($email !== '' && \App\Models\User::query()->where('email', $email)->exists()) {
                $validator->errors()->add(
                    'donor_email',
                    'A donor with this email already exists. Please choose an existing donor instead.',
                );
            }

            $phoneNumber = trim((string) $this->input('donor_phone', ''));
            if ($phoneNumber !== '' && app(PhoneNumberService::class)->isRegistered($phoneNumber)) {
                $validator->errors()->add(
                    'donor_phone',
                    'A donor with this phone number already exists. Please choose an existing donor instead.',
                );
            }
        });
    }
}
