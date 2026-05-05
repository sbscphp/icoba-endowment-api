<?php

namespace App\Http\Requests\Auth;

use App\Enums\eClientType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class LoginRequest extends ApiFormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'client' => ['nullable', Rule::in(eClientType::values())],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'email' => 'email address',
            'client' => 'client type',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Please enter your password.',
            'client.in' => 'Please select a valid client type.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email)),
            'client' => $this->client ? strtolower(trim($this->client)) : null,
        ]);
    }
}
