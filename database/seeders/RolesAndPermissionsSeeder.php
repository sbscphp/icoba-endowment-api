<?php

namespace Database\Seeders;

use App\Enums\ePermission as PermissionEnum;
use App\Enums\eRole as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    // php artisan db:seed --class=RolesAndPermissionsSeeder

    use WithoutModelEvents;

    private const GUARD = 'api';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->syncPermissionsFromEnum();
        $this->removeObsoletePermissions();
        $this->upsertAllowableRoles();
        $this->grantDefaultRolePermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function syncPermissionsFromEnum(): void
    {
        foreach (PermissionEnum::cases() as $perm) {
            Permission::firstOrCreate([
                'name' => $perm->value,
                'guard_name' => self::GUARD,
            ]);
        }
    }

    /**
     * Remove permission rows not defined in ePermission (e.g. legacy names).
     */
    private function removeObsoletePermissions(): void
    {
        Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', PermissionEnum::values())
            ->delete();
    }

    private function upsertAllowableRoles(): void
    {
        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::firstOrCreate(
                [
                    'name' => $roleEnum->value,
                    'guard_name' => self::GUARD,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                ]
            );

            if ($role->uuid === null || $role->uuid === '') {
                $role->forceFill(['uuid' => (string) Str::uuid()])->save();
            }
        }
    }

    /**
     * Idempotent: only adds missing permissions (givePermissionTo).
     * Does not strip custom grants on seeded roles.
     */
    private function grantDefaultRolePermissions(): void
    {
        $allPermissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', PermissionEnum::values())
            ->get();

        $superAdmin = Role::query()
            ->where('name', RoleEnum::SUPER_ADMIN->value)
            ->where('guard_name', self::GUARD)
            ->firstOrFail();

        foreach ($allPermissions as $permission) {
            $superAdmin->givePermissionTo($permission);
        }

        $adminDenied = [
            PermissionEnum::ROLES_DELETE->value,
            PermissionEnum::ADMINS_DELETE->value,
        ];

        foreach ([RoleEnum::ADMIN] as $roleEnum) {
            $role = Role::query()
                ->where('name', $roleEnum->value)
                ->where('guard_name', self::GUARD)
                ->firstOrFail();

            foreach ($allPermissions as $permission) {
                if (in_array($permission->name, $adminDenied, true)) {
                    continue;
                }
                $role->givePermissionTo($permission);
            }
        }
    }
}
