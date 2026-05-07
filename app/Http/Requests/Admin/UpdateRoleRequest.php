<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $roleId = (string) $this->route('roleId');
        $role = Role::query()
            ->where('guard_name', 'api')
            ->where(function ($query) use ($roleId): void {
                $query->where('uuid', $roleId);
                if (is_numeric($roleId)) {
                    $query->orWhere('id', (int) $roleId);
                }
            })
            ->first();

        return [
            'name' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('roles', 'name')->ignore($role?->id)->where(
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
}
