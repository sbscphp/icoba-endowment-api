<?php

namespace App\Http\Requests\Customer\Settings;

use App\Http\Requests\ApiFormRequest;

class CustomerProfileUpdateRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'firstname' => ['sometimes', 'string', 'max:255'],
            'lastname' => ['sometimes', 'string', 'max:255'],
            'middlename' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'email' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.prohibited' => 'Email cannot be changed.',
        ]);
    }
}
