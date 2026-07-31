<?php

namespace App\Http\Requests\Auth;

use App\Enums\DonorTypeSlug;
use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesOtpChannel;
use App\Models\Country;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DonorRegisterRequest extends ApiFormRequest
{
    use ValidatesOtpChannel;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $slug = strtolower(trim((string) $this->input('donor_type')));

        $shared = array_merge($this->otpChannelRules(), [
            'donor_type' => ['required', 'string', Rule::in(DonorTypeSlug::values()), Rule::exists('donor_types', 'slug')],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'phone_number' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'country_uuid' => ['nullable', 'uuid', Rule::exists('countries', 'uuid')->where('is_active', true)],
            'country_code' => ['nullable', 'string', 'max:10'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ]);

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($shared, [
                'firstname' => $this->personNameRules(),
                'lastname' => $this->personNameRules(),
                'set_number' => ['required', 'string', 'max:16', Rule::exists('sets', 'set_number')],
                'alumni_identifier' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9]*$/'],
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($shared, [
                'organization_name' => ['required', 'string', 'min:2', 'max:100'],
                'corporate_category_uuid' => ['required', 'uuid', Rule::exists('corporate_categories', 'uuid')],
                'rc_number' => ['required', 'string', 'min:2', 'max:64'],
                'tin' => ['required', 'string', 'min:2', 'max:64'],
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value, DonorTypeSlug::WIVES_OF_ICOBA->value => array_merge($shared, [
                'firstname' => $this->personNameRules(),
                'lastname' => $this->personNameRules(),
            ]),
            default => $shared,
        };
    }

    /**
     * @return list<string|ValidationRule>
     */
    private function personNameRules(): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:50',
            'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u',
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'otp_channel.in' => 'Verification channel must be either email or sms.',
            'email.unique' => 'An account with this email already exists. Would you like to log in instead, or use a different email?',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check your graduation year or contact ICOBA support.',
            'phone_number.regex' => 'Please enter a valid phone number for the selected country.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDefaultOtpChannel();

        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'donor_type' => strtolower(trim((string) $this->input('donor_type'))),
        ]);

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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $phoneNumber = (string) $this->input('phone_number');

            if (app(PhoneNumberService::class)->isRegistered($phoneNumber)) {
                $validator->errors()->add(
                    'phone_number',
                    'An account with this phone number already exists. Would you like to log in instead, or use a different phone number?',
                );
            }
        });
    }
}
