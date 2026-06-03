<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;

class CreateAdminRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:admins,email'],
            'role_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists((new Role)->getTable(), 'uuid')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'can_login' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.unique' => 'An admin with this email already exists.',
            'role_id.exists' => 'The selected role does not exist.',
            'role_id.uuid' => 'The selected role is invalid.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => strtolower(trim($email))]);
        }
    }
}
