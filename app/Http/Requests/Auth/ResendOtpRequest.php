<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class ResendOtpRequest extends ApiFormRequest
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
        ];
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
        ]);
    }
}
