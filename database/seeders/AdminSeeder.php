<?php

namespace Database\Seeders;

use App\Enums\eRole;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Two admin users: {app-slug}-admin-1|2@yopmail.com (password: "password"). App slug from config('app.name').
 * Roles: super_admin, admin.
 *
 * php artisan db:seed --class=AdminSeeder
 */
class AdminSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // $prefix = Str::slug((string) config('app.name')) ?: 'app';

        $accounts = [
            ['suffix' => '1', 'name' => 'Super Admin', 'role' => eRole::SUPER_ADMIN],
            ['suffix' => '2', 'name' => 'Admin', 'role' => eRole::ADMIN],
        ];

        foreach ($accounts as $row) {
            $email = "endowment-admin-{$row['suffix']}@yopmail.com";
            // $email = "{$prefix}-admin-{$row['suffix']}@yopmail.com";

            $admin = Admin::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $row['name'],
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'can_login' => true,
                    'password' => Hash::make(self::PASSWORD),
                ]
            );
            $admin->syncRoles([$row['role']->value]);
        }
    }
}
