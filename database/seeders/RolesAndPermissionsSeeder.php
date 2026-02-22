<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\eRole as RoleEnum;
use App\Enums\ePermission as PermissionEnum;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create permissions
        foreach (PermissionEnum::cases() as $perm) {
            Permission::firstOrCreate([
                'name' => $perm->value,
                'guard_name' => 'api',
            ]);
        }

        // Create roles
        $superAdmin = Role::firstOrCreate([
            'name' => RoleEnum::SUPER_ADMIN->value,
            'guard_name' => 'api',
        ]);

        $admin = Role::firstOrCreate([
            'name' => RoleEnum::ADMIN->value,
            'guard_name' => 'api',
        ]);

        $platformAdmin = Role::firstOrCreate([
            'name' => RoleEnum::PLATFORM_ADMIN->value,
            'guard_name' => 'api',
        ]);

        $systemAdmin = Role::firstOrCreate([
            'name' => RoleEnum::SYSTEM_ADMIN->value,
            'guard_name' => 'api',
        ]);

        $customer = Role::firstOrCreate([
            'name' => RoleEnum::CUSTOMER->value,
            'guard_name' => 'api',
        ]);

        // Assign permissions
        $admin->syncPermissions(
            array_map(fn($p) => $p->value, PermissionEnum::cases())
        );

        $systemAdmin->syncPermissions([
            PermissionEnum::PAYMENTS_VIEW->value,
            PermissionEnum::DOCUMENT_VIEW->value,
            PermissionEnum::DOCUMENT_MANAGE->value,
            PermissionEnum::USERS_MANAGE->value,
            PermissionEnum::DOCUMENT_APPROVE->value,
        ]);

        $platformAdmin->syncPermissions([
            PermissionEnum::PAYMENTS_VIEW->value,
            PermissionEnum::DOCUMENT_VIEW->value,
            PermissionEnum::DOCUMENT_MANAGE->value,
            PermissionEnum::USERS_MANAGE->value,
            PermissionEnum::DOCUMENT_APPROVE->value,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
