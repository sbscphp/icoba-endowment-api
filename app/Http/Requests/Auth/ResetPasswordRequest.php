<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Support\PasswordRules;

class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRules::make()],
            'password_confirmation' => ['required'],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'reset_token' => 'reset token',
            'password' => 'new password',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'reset_token.required' => 'The reset token is required.',
            'password.required' => 'Please enter your new password.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password_confirmation.required' => 'Please confirm your new password.',
        ]);
    }
}
