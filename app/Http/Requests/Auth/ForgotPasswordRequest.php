<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesOtpChannel;

class ForgotPasswordRequest extends ApiFormRequest
{
    use ValidatesOtpChannel;

    public function rules(): array
    {
        return array_merge($this->otpChannelRules(), [
            'email' => ['required', 'email'],
        ]);
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'email' => 'email address',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'otp_channel.in' => 'Verification channel must be either email or sms.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeDefaultOtpChannel();

        $this->merge([
            'email' => strtolower(trim((string) $this->email)),
        ]);
    }
}
