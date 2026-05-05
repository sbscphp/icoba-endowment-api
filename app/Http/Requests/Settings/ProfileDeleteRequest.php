<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class ProfileDeleteRequest extends ApiFormRequest
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
            'password' => $this->currentPasswordRules(),
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'password' => 'current password',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'password.required' => 'Please enter your current password.',
            'password.current_password' => 'The current password is incorrect.',
        ]);
    }
}
