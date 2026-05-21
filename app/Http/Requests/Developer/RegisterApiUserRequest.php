<?php

namespace App\Http\Requests\Developer;

use App\Enums\Api\ApiEncryptionMode;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class RegisterApiUserRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('api_users', 'email')],
            'name' => ['nullable', 'string', 'max:255'],
            'encryption_mode' => [
                'nullable',
                'string',
                Rule::enum(ApiEncryptionMode::class),
            ],
        ];
    }
}
