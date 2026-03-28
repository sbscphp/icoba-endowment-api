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
            ['email' => 'apfspn-superadmin@yopmail.com'],
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
            ['email' => 'apfspn-admin@yopmail.com'],
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

        $customer = User::firstOrCreate(
            ['email' => 'apfspn-customer@yopmail.com'],
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
