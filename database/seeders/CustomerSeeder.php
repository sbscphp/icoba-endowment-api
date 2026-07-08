<?php

namespace Database\Seeders;

use App\Enums\eRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two customer users: nhef-customer-1|2@yopmail.com (password: "password").
 * Customer 1 has 2FA off; customer 2 has 2FA on.
 *
 * php artisan db:seed --class=CustomerSeeder
 */
class CustomerSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $accounts = [
            [
                'suffix' => '1',
                'firstname' => 'Customer',
                'lastname' => 'One',
                '2fa' => false,
            ],
            [
                'suffix' => '2',
                'firstname' => 'Customer',
                'lastname' => 'Two',
                '2fa' => true,
            ],
        ];

        foreach ($accounts as $row) {
            $email = "nhef-customer-{$row['suffix']}@yopmail.com";

            $customer = User::firstOrCreate(
                ['email' => $email],
                [
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'phone_number' => '198765432',
                    'country_code' => '+234',
                    '2fa' => $row['2fa'],
                    'is_active' => true,
                    'can_login' => true,
                    'password' => Hash::make(self::PASSWORD),
                ]
            );

            $customer->syncRoles([eRole::CUSTOMER->value]);
        }
    }
}
