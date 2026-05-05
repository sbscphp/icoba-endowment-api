<?php

namespace Database\Seeders;

use App\Enums\eRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    // php artisan db:seed --class=UsersSeeder
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $customer = User::firstOrCreate(
            ['email' => strtolower(env('APP_NAME')).'-customer@yopmail.com'],
            [
                'firstname' => 'Customer',
                'lastname' => 'User',
                'phone_number' => '198765432',
                'country_code' => '+234',
                '2fa' => false,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $customer->assignRole(eRole::CUSTOMER->value);

        $customer2 = User::firstOrCreate(
            ['email' => strtolower(env('APP_NAME')).'-customer2@yopmail.com'],
            [
                'firstname' => 'Customer',
                'lastname' => 'User Two',
                'phone_number' => '198765432',
                'country_code' => '+234',
                '2fa' => true,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make('password'),
            ]
        );
        $customer2->assignRole(eRole::CUSTOMER->value);
    }
}
