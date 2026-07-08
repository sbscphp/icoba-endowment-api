<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesOtpChannel;
use Illuminate\Contracts\Validation\ValidationRule;

class ResendOtpRequest extends ApiFormRequest
{
    use ValidatesOtpChannel;
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->otpChannelRules(), [
            'challenge_token' => ['required', 'string'],
        ]);
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'challenge_token' => 'verification session',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'challenge_token.required' => 'The verification session is required.',
            'otp_channel.in' => 'Verification channel must be either email or sms.',
        ]);
    }
}
