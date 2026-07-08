<?php

namespace App\Http\Requests\Auth;

use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesOtpChannel;
use App\Models\Country;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerRegisterRequest extends ApiFormRequest
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
        return array_merge($this->otpChannelRules(), [
            'firstname' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u'],
            'lastname' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\'\-]+(?:\s[\p{L}\'\-]+)*$/u'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'phone_number' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'country_uuid' => ['nullable', 'uuid', Rule::exists('countries', 'uuid')->where('is_active', true)],
            'country_code' => ['nullable', 'string', 'max:10'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'otp_channel.in' => 'Verification channel must be either email or sms.',
            'email.unique' => 'An account with this email already exists. Would you like to log in instead, or use a different email?',
            'phone_number.regex' => 'Please enter a valid phone number for the selected country.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDefaultOtpChannel();

        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'firstname' => trim((string) $this->input('firstname')),
            'lastname' => trim((string) $this->input('lastname')),
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
