<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class VerifyResetOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string'],
            'otp' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'challenge_token' => 'verification session',
            'otp' => 'verification code',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'challenge_token.required' => 'The verification session is required.',
            'otp.required' => 'Please enter the verification code.',
        ]);
    }
}
