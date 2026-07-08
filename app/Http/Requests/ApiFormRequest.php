<?php

namespace App\Http\Requests;

use App\Responser\JsonResponser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

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
        /** @var JsonResponse $response */
        $response = JsonResponser::send(
            error: true,
            message: $validator->errors()->first() ?: 'Validation error',
            data: $validator->errors()->messages(),
            statusCode: 422,
        );

        throw new HttpResponseException($response);
    }
}
