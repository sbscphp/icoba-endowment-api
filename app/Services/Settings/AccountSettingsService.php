<?php

namespace App\Services\Settings;

use App\Enums\DonorTypeSlug;
use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Models\Admin;
use App\Models\GraduationSet;
use App\Models\User;
use App\Support\CustomerProfileUpdateFields;
use App\Support\PasswordRules;
use App\Services\GivingIdentity\GivingIdentityLockService;
use Illuminate\Support\Facades\Hash;

class AccountSettingsService
{
    public function __construct(
        private readonly GivingIdentityLockService $givingIdentityLock,
    ) {}
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
        }

        $updates = [
            'password' => $newPassword,
        ];

        if ($authenticatable instanceof Admin) {
            $updates['must_reset_password'] = false;
        }

        $authenticatable->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateCustomerProfile(User $user, array $data): array
    {
        $user->loadMissing('donorType');
        $slug = $user->donorType?->slug;
        $data = CustomerProfileUpdateFields::filterForDonorType($slug, $data);

        $blockedFields = $this->givingIdentityLock->lockedProfileFieldsBeingChanged($user, $data);
        if ($blockedFields !== []) {
            throw new ApiException(
                'Your giving profile cannot be changed after a successful donation. Contact ICOBA support if you need assistance.',
                422,
            );
        }

        $updates = [];

        foreach (['phone_number', 'country_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => $updates = array_merge($updates, $this->alumniProfileUpdates($data)),
            DonorTypeSlug::CORPORATE_DONOR->value => $updates = array_merge($updates, $this->corporateProfileUpdates($data)),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value => $updates = array_merge($updates, $this->individualProfileUpdates($data)),
            default => null,
        };

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return $this->customerProfile($user->fresh() ?? $user);
    }

    /**
     * @return array{2fa: bool}
     */
    public function toggleCustomerTwoFactor(User $user, bool $enabled): array
    {
        $user->forceFill(['2fa' => $enabled])->save();

        return [
            '2fa' => (bool) $user->{'2fa'},
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function alumniProfileUpdates(array $data): array
    {
        $updates = $this->individualProfileUpdates($data);

        if (array_key_exists('alumni_identifier', $data)) {
            $updates['alumni_identifier'] = filled($data['alumni_identifier']) ? $data['alumni_identifier'] : null;
        }

        if (array_key_exists('set_number', $data)) {
            $updates['graduation_set_uuid'] = GraduationSet::query()
                ->where('set_number', $data['set_number'])
                ->value('uuid');
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function corporateProfileUpdates(array $data): array
    {
        $updates = [];

        foreach (['organization_name', 'rc_number', 'tin', 'corporate_category_uuid'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('organization_name', $data)) {
            $updates['firstname'] = $data['organization_name'];
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function individualProfileUpdates(array $data): array
    {
        $updates = [];

        foreach (['firstname', 'lastname', 'middlename'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        return $updates;
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

