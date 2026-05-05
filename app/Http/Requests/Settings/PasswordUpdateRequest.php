<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordUpdateRequest extends ApiFormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'password' => 'new password',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'current_password.required' => 'Please enter your current password.',
            'password.required' => 'Please enter your new password.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);
    }
}
