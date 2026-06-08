<?php

namespace App\Http\Resources\Auth;

use App\Enums\CustomerRegistrationStepEnum;
use App\Enums\UserTypeEnum;
use App\Helpers\PermissionModuleMapper;
use App\Http\Resources\UserResource;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = data_get($this->resource, 'user');

        return [
            'access_token' => data_get($this->resource, 'access_token'),
            'refresh_token' => data_get($this->resource, 'refresh_token'),
            'token_type' => data_get($this->resource, 'token_type'),
            'expires_in' => data_get($this->resource, 'expires_in'),
            'refresh_expires_in' => data_get($this->resource, 'refresh_expires_in'),
            'registration_step' => data_get(
                $this->resource,
                'registration_step',
                CustomerRegistrationStepEnum::COMPLETED->value
            ),
            'user_type' => $user instanceof Admin ? UserTypeEnum::ADMIN->value : UserTypeEnum::CUSTOMER->value,
            'user' => $user instanceof Admin ? $this->adminPayload($user) : UserResource::make($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPayload(Admin $admin): array
    {
        $payload = [
            'uuid' => $admin->uuid,
            'name' => $admin->name,
            'email' => $admin->email,
            'email_verified_at' => $admin->email_verified_at,
            'email_notifications_enabled' => (bool) $admin->email_notifications_enabled,
            'push_notifications_enabled' => (bool) $admin->push_notifications_enabled,
            'is_active' => $admin->is_active,
            'can_login' => $admin->can_login,
            'must_reset_password' => (bool) $admin->must_reset_password,
            'last_login_at' => $admin->last_login_at,
            'last_active_at' => $admin->last_active_at,
            'created_at' => $admin->created_at,
            'updated_at' => $admin->updated_at,
        ];

        if (! config('security.login_reveal_permissions', true)) {
            return $payload;
        }

        $admin->loadMissing(['roles', 'permissions']);
        $permissionNames = $admin->getAllPermissions()->pluck('name')->values()->all();

        return array_merge($payload, [
            'roles' => $admin->roles->pluck('name')->values(),
            'permissions' => $permissionNames,
            'permissions_by_module' => PermissionModuleMapper::groupedApiPermissionsForNames($permissionNames),
        ]);
    }
}
