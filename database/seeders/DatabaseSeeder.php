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
            DefaultCampaignSeeder::class,
            ThemeSeeder::class,
            ApiUserSeeder::class,
        ]);
    }
}
