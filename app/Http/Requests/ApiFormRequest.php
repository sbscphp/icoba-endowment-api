<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'new_password' => 'new password',
            'new_password_confirmation' => 'password confirmation',
            'password' => 'password',
            'password_confirmation' => 'password confirmation',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'email' => 'Please provide a valid email address.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'current_password' => 'The current password is incorrect.',
            'in' => 'The selected :attribute is invalid.',
            'string' => 'The :attribute must be a valid text value.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => true,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
            'data' => null,
        ], 422));
    }
}
