<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CountrySeeder::class,
            DonorRegistrationSeeder::class,
            SetsTableSeeder::class,
            CustomerSeeder::class,
            // UsersSeeder::class,
            AdminSeeder::class,
            ThemeSeeder::class,
            TierConfigurationSeeder::class,
            HeroSlideSeeder::class,
            CertificateTemplateSeeder::class,
            ApiUserSeeder::class,
        ]);
    }
}
