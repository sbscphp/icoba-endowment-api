<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class DonorRegistrationSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $donorTypes = [
            ['id' => 1, 'slug' => 'icoba_alumni', 'label' => 'ICOBA Alumni', 'description' => 'Donate as an old boy of Igbobi College, Lagos', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'slug' => 'corporate_donor', 'label' => 'Corporate Donor', 'description' => 'Donate to Igbobi College as an organization, company or foundation.', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'slug' => 'friends_relatives', 'label' => 'Friends & Relatives of ICOBA', 'description' => 'Donate to Igbobi College as a friend or relative to an Igbobi college alumnus.', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($donorTypes as &$row) {
            $row['uuid'] = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'donor-type:'.$row['slug'])->toString();
        }
        unset($row);

        DB::table('donor_types')->upsert(
            $donorTypes,
            ['slug'],
            ['uuid', 'label', 'description', 'updated_at']
        );

        $corporateCategories = [
            ['id' => 1, 'name' => 'Company', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Foundation', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'NGO', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Government', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Corporation', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => 'Partnership', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'name' => 'Sole Proprietorship', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'name' => 'LLC', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'name' => 'Cooperative', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 10, 'name' => 'Trust', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'name' => 'Association', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'name' => 'Charity', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 13, 'name' => 'Social Enterprise', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 14, 'name' => 'Multinational', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 15, 'name' => 'Franchise', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 16, 'name' => 'Joint Venture', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 17, 'name' => 'Holding Company', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 18, 'name' => 'Nonprofit Organization', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 19, 'name' => 'Other', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($corporateCategories as &$row) {
            $row['uuid'] = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'corporate-category:'.$row['id'])->toString();
        }
        unset($row);

        DB::table('corporate_categories')->upsert(
            $corporateCategories,
            ['id'],
            ['uuid', 'name', 'updated_at']
        );

        $this->command?->info('Donor types and corporate categories seeded.');
    }
}
