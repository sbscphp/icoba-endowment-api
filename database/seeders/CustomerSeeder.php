<?php

namespace Database\Seeders;

use App\Enums\eRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Two customer users: {app-slug}-customer-1|2@yopmail.com (password: "password"). App slug from config('app.name').
 *
 * php artisan db:seed --class=CustomerSeeder
 */
class CustomerSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** Nigerian calling prefix (matches users.country_code column). */
    private const COUNTRY_CODE = '+234';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // $prefix = Str::slug((string) config('app.name')) ?: 'app';

        $accounts = [
            ['suffix' => '1', 'firstname' => 'Customer', 'lastname' => 'One', 'phone' => '08001001001'],
            ['suffix' => '2', 'firstname' => 'Customer', 'lastname' => 'Two', 'phone' => '08002002002'],
        ];

        foreach ($accounts as $row) {
            $email = "endowment-customer-{$row['suffix']}@yopmail.com";
            // $email = "{$prefix}-customer-{$row['suffix']}@yopmail.com";

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'phone_number' => $row['phone'],
                    'country_code' => self::COUNTRY_CODE,
                    'email_verified_at' => now(),
                    '2fa' => false,
                    'is_active' => true,
                    'can_login' => true,
                    'password' => Hash::make(self::PASSWORD),
                ]
            );
            $user->assignRole(eRole::CUSTOMER->value);
        }
    }
}
