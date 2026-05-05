<?php

namespace App\Http\Requests\Auth;

use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DonorRegisterRequest extends ApiFormRequest
{
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

        $shared = [
            'donor_type' => ['required', 'string', Rule::in(['icoba_alumni', 'corporate_donor', 'friends_relatives']), Rule::exists('donor_types', 'slug')],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ];

        return match ($slug) {
            'icoba_alumni' => array_merge($shared, [
                'firstname' => $this->personNameRules(),
                'lastname' => $this->personNameRules(),
                'set_number' => ['required', 'string', 'max:16', Rule::exists('sets', 'set_number')],
                'alumni_identifier' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9]*$/'],
            ]),
            'corporate_donor' => array_merge($shared, [
                'organization_name' => ['required', 'string', 'min:2', 'max:100'],
                'corporate_category_uuid' => ['required', 'uuid', Rule::exists('corporate_categories', 'uuid')],
            ]),
            'friends_relatives' => array_merge($shared, [
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
            'email.unique' => 'An account with this email already exists. Would you like to log in instead, or use a different email?',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check your graduation year or contact ICOBA support.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'donor_type' => strtolower(trim((string) $this->input('donor_type'))),
        ]);

        $this->normalizePhoneAndCountry();
    }

    private function normalizePhoneAndCountry(): void
    {
        $cc = $this->input('country_code');
        $cc = ($cc !== null && $cc !== '') ? trim((string) $cc) : '+234';
        if ($cc !== '' && ! str_starts_with($cc, '+')) {
            $cc = '+'.$cc;
        }

        $raw = (string) $this->input('phone_number', '');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            $this->merge([
                'country_code' => $cc,
                'phone_number' => '',
            ]);

            return;
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10 && $cc === '+234') {
            $phone = '+234'.substr($digits, 1);
        } elseif (! str_starts_with($raw, '+') && $cc !== '') {
            $phone = $cc.$digits;
        } else {
            $phone = str_starts_with($raw, '+') ? '+'.$digits : $cc.$digits;
        }

        $this->merge([
            'country_code' => $cc,
            'phone_number' => $phone,
        ]);
    }
}
