<?php

namespace App\Http\Requests\Auth;

use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class RefreshTokenRequest extends ApiFormRequest
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
        return [
            'refresh_token' => ['required', 'string'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'refresh_token' => 'refresh token',
            'client' => 'client type',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'refresh_token.required' => 'Please provide your refresh token.',
            'client.in' => 'Please select a valid client type.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'refresh_token' => is_string($this->refresh_token) ? trim($this->refresh_token) : $this->refresh_token,
            'client' => $this->client ? strtolower(trim($this->client)) : null,
        ]);
    }
}
