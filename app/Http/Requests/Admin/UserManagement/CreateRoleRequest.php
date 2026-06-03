<?php

namespace App\Http\Requests\Admin\UserManagement;

use App\Http\Requests\ApiFormRequest;
use App\Models\Permission;
use Illuminate\Validation\Rule;

class CreateRoleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('roles', 'name')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists((new Permission)->getTable(), 'name')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.unique' => 'A role with this name already exists.',
            'name.max' => 'Role name may not be longer than 50 characters.',
            'description.max' => 'Description may not be longer than 255 characters.',
            'permissions.array' => 'Permissions must be provided as a list.',
            'permissions.*.exists' => 'One or more of the selected permissions are invalid.',
        ]);
    }
}
