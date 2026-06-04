<?php

namespace App\Http\Requests\Admin\UserManagement;

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
            'frontend_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.max' => 'Admin name may not be longer than 255 characters.',
            'email.unique' => 'An admin with this email already exists.',
            'email.max' => 'Email address may not be longer than 255 characters.',
            'role_id.required' => 'Please select a role for the admin.',
            'role_id.uuid' => 'The selected role is invalid.',
            'role_id.exists' => 'The selected role does not exist.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => strtolower(trim($email))]);
        }

        $frontendUrl = $this->input('frontend_url');
        if (is_string($frontendUrl)) {
            $this->merge(['frontend_url' => rtrim(trim($frontendUrl), '/')]);
        }
    }
}
