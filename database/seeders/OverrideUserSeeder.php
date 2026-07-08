<?php

namespace Database\Seeders;

use App\Enums\eRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Dev/support accounts for encryption override testing.
 *
 * Prerequisites (customer override):
 *   php artisan db:seed --class=DonorRegistrationSeeder
 *   php artisan db:seed --class=SetsTableSeeder
 *
 * php artisan db:seed --class=OverrideUserSeeder
 */
class OverrideUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const COUNTRY_CODE = '+234';

    private const ICOBA_ALUMNI_SLUG = 'icoba_alumni';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        /** @var list<string> $emails */
        $emails = config('security.override_users.emails', []);
        $adminEmail = $emails[0] ?? 'admin-override@yopmail.com';
        $customerEmail = $emails[1] ?? 'customer-override@yopmail.com';

        $this->seedAdminOverride($adminEmail);
        $this->seedCustomerOverride($customerEmail);
    }

    private function seedAdminOverride(string $email): void
    {
        $admin = Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin Override',
                'email_verified_at' => now(),
                '2fa' => false,
                'is_active' => true,
                'can_login' => true,
                'password' => Hash::make(self::PASSWORD),
            ]
        );

        $admin->syncRoles([eRole::ADMIN->value]);
    }

    private function seedCustomerOverride(string $email): void
    {
        $attributes = [
            'firstname' => 'Customer',
            'lastname' => 'Override',
            'phone_number' => '08099990001',
            'country_code' => self::COUNTRY_CODE,
            'email_verified_at' => now(),
            '2fa' => false,
            'is_active' => true,
            'can_login' => true,
            'password' => Hash::make(self::PASSWORD),
        ];

        if (! $this->canSeedAlumniProfile()) {
            throw new RuntimeException(
                'Donor registration schema is missing on users. Run migrations, then seed DonorRegistrationSeeder and SetsTableSeeder.'
            );
        }

        $attributes = array_merge($attributes, $this->alumniProfileAttributes());

        $user = User::updateOrCreate(
            ['email' => $email],
            $attributes,
        );

        $user->assignRole(eRole::CUSTOMER->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function alumniProfileAttributes(): array
    {
        $donorTypeUuid = DB::table('donor_types')
            ->where('slug', self::ICOBA_ALUMNI_SLUG)
            ->value('uuid');

        if ($donorTypeUuid === null) {
            throw new RuntimeException(
                'ICOBA Alumni donor type not found. Run: php artisan db:seed --class=DonorRegistrationSeeder'
            );
        }

        $graduationSetUuid = DB::table('sets')
            ->orderBy('set_number')
            ->orderBy('id')
            ->value('uuid');

        if ($graduationSetUuid === null) {
            throw new RuntimeException(
                'No graduation set found. Run: php artisan db:seed --class=SetsTableSeeder'
            );
        }

        return [
            'donor_type_uuid' => $donorTypeUuid,
            'graduation_set_uuid' => $graduationSetUuid,
            'alumni_identifier' => 'OVERRIDE-001',
        ];
    }

    private function canSeedAlumniProfile(): bool
    {
        if (! Schema::hasTable('donor_types') || ! Schema::hasTable('sets')) {
            return false;
        }

        return Schema::hasColumn('users', 'donor_type_uuid')
            && Schema::hasColumn('users', 'graduation_set_uuid')
            && Schema::hasColumn('users', 'alumni_identifier');
    }
}
