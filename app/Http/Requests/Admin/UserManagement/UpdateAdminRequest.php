<?php

namespace App\Http\Requests\Admin\UserManagement;

use App\Http\Requests\ApiFormRequest;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $adminId = (string) $this->route('adminId');
        $admin = Admin::query()
            ->where('uuid', $adminId)
            ->orWhere('id', is_numeric($adminId) ? (int) $adminId : -1)
            ->first();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($admin?->id),
            ],
            'role_id' => [
                'sometimes',
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
            'name.max' => 'Admin name may not be longer than 255 characters.',
            'email.unique' => 'Another admin is already using this email.',
            'email.max' => 'Email address may not be longer than 255 characters.',
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
    }
}
