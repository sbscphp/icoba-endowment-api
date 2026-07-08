<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CountrySeeder::class,
            AdminSeeder::class,
            CustomerSeeder::class,
            ThemeSeeder::class,
            ApiUserSeeder::class,
        ]);
    }
}
