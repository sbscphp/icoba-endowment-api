<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Enums\eRole;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdmin = User::firstOrCreate(
            ['email' => 'quiva-superadmin@yopmail.com'],
            [
                'firstname' => 'Super Admin',
                'lastname' => 'User',
                'phone_number' => '09087654322',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $superAdmin->assignRole(eRole::SUPER_ADMIN->value);

        $admin = User::firstOrCreate(
            ['email' => 'quiva-admin@yopmail.com'],
            [
                'firstname' => 'Admin',
                'lastname' => 'User',
                'phone_number' => '1234567890',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $admin->assignRole(eRole::ADMIN->value);

        $platformAdmin = User::firstOrCreate(
            ['email' => 'quiva-platformadmin@yopmail.com'],
            [
                'firstname' => 'Platform Admin',
                'lastname' => 'User',
                'phone_number' => '08098766544',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $platformAdmin->assignRole(eRole::PLATFORM_ADMIN->value);

        $systemAdmin = User::firstOrCreate(
            ['email' => 'quiva-systemadmin@yopmail.com'],
            [
                'firstname' => 'System Admin',
                'lastname' => 'User',
                'phone_number' => '07099887766',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $systemAdmin->assignRole(eRole::SYSTEM_ADMIN->value);

        $customer = User::firstOrCreate(
            ['email' => 'quiva-customer@yopmail.com'],
            [
                'firstname' => 'Customer',
                'lastname' => 'User',
                'phone_number' => '198765432',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $customer->assignRole(eRole::CUSTOMER->value);
    }
}
