<?php

namespace App\Services\Settings;

use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Models\Admin;
use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Support\Facades\Hash;

class AccountSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function customerProfile(User $user): array
    {
        $user->loadMissing(['roles', 'donorType', 'corporateCategory', 'graduationSet']);

        return UserResource::make($user)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public function adminProfile(Admin $admin): array
    {
        $admin->loadMissing(['roles', 'permissions']);

        return [
            'uuid' => $admin->uuid,
            'name' => $admin->name,
            'email' => $admin->email,
            '2fa' => (bool) $admin->{'2fa'},
            'is_active' => (bool) $admin->is_active,
            'can_login' => (bool) $admin->can_login,
            'must_reset_password' => (bool) $admin->must_reset_password,
            'email_notifications_enabled' => (bool) $admin->email_notifications_enabled,
            'push_notifications_enabled' => (bool) $admin->push_notifications_enabled,
            'roles' => $admin->roles->pluck('name')->values(),
            'permissions' => $admin->getAllPermissions()->pluck('name')->values(),
            'last_login_at' => $admin->last_login_at,
            'last_active_at' => $admin->last_active_at,
            'created_at' => $admin->created_at,
            'updated_at' => $admin->updated_at,
        ];
    }

    public function updatePassword(User|Admin $authenticatable, string $newPassword): void
    {
        $this->validatePasswordStrength($newPassword);

        if (Hash::check($newPassword, (string) $authenticatable->password)) {
            throw new ApiException('You cannot reuse your current password.', 422);
        }        $updates = [
            'password' => $newPassword,
        ];

        if ($authenticatable instanceof Admin) {
            $updates['must_reset_password'] = false;
    \}

        $authenticatable->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    public function updateNotificationPreferences(User|Admin $authenticatable, array $data): array
    {
        $authenticatable->forceFill([
            'email_notifications_enabled' => (bool) ($data['email_notifications_enabled'] ?? $authenticatable->email_notifications_enabled),
            'push_notifications_enabled' => (bool) ($data['push_notifications_enabled'] ?? $authenticatable->push_notifications_enabled),
        ])->save();

        return [
            'email_notifications_enabled' => (bool) $authenticatable->email_notifications_enabled,
            'push_notifications_enabled' => (bool) $authenticatable->push_notifications_enabled,
        ];
    }

    private function validatePasswordStrength(string $password): void
    {
        validator(
            ['password' => $password],
            ['password' => ['required', 'string', PasswordRules::make()]]
        )->validate();
    }
}

