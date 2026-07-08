<?php

namespace App\Http\Requests\Auth;

use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends ApiFormRequest
{
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
        return [
            'challenge_token' => ['required', 'string'],
            'otp' => ['required', 'string'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'challenge_token' => 'verification session',
            'otp' => 'verification code',
            'client' => 'client type',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'challenge_token.required' => 'The verification session is required.',
            'otp.required' => 'Please enter the verification code.',
            'client.in' => 'Please select a valid client type.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client' => $this->client ? strtolower(trim($this->client)) : null,
        ]);
    }
}
