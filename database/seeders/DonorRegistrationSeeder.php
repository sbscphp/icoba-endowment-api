<?php

namespace Database\Seeders;

use App\Enums\DonorTypeSlug;
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
            ['id' => 1, 'slug' => DonorTypeSlug::ICOBA_ALUMNI->value, 'label' => DonorTypeSlug::ICOBA_ALUMNI->label(), 'description' => DonorTypeSlug::ICOBA_ALUMNI->description(), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'slug' => DonorTypeSlug::CORPORATE_DONOR->value, 'label' => DonorTypeSlug::CORPORATE_DONOR->label(), 'description' => DonorTypeSlug::CORPORATE_DONOR->description(), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'slug' => DonorTypeSlug::FRIENDS_OF_ICOBA->value, 'label' => DonorTypeSlug::FRIENDS_OF_ICOBA->label(), 'description' => DonorTypeSlug::FRIENDS_OF_ICOBA->description(), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'slug' => DonorTypeSlug::RELATIVES_OF_ICOBA->value, 'label' => DonorTypeSlug::RELATIVES_OF_ICOBA->label(), 'description' => DonorTypeSlug::RELATIVES_OF_ICOBA->description(), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'slug' => DonorTypeSlug::WIVES_OF_ICOBA->value, 'label' => DonorTypeSlug::WIVES_OF_ICOBA->label(), 'description' => DonorTypeSlug::WIVES_OF_ICOBA->description(), 'created_at' => $now, 'updated_at' => $now],
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
            ['id' => 18, 'name' => 'Non-Profit Organization', 'created_at' => $now, 'updated_at' => $now],
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
