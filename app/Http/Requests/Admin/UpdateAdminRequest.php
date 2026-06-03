<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\Role;
use App\Http\Requests\ApiFormRequest;
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
            'email.unique' => 'Another admin is already using this email.',
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
