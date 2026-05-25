<?php

namespace Database\Seeders;

use App\Models\TierConfiguration;
use Illuminate\Database\Seeder;

class TierConfigurationSeeder extends Seeder
{
    // php artisan db:seed --class=TierConfigurationSeeder

    public function run(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/').'/assets/tiers';

        $tiers = [
            [
                'name' => 'Friends of Igbobi College',
                'description' => 'Default recognition tier for supporters contributing below ₦1 million.',
                'min_amount' => 0,
                'max_amount' => 999999.99,
                'sort_order' => 1,
                'badge_slug' => 'friends-of-igbobi-college',
            ],
            [
                'name' => 'Bronze Contributor',
                'description' => 'Recognition for cumulative contributions from ₦1 million to ₦9 million.',
                'min_amount' => 1000000,
                'max_amount' => 9999999.99,
                'sort_order' => 2,
                'badge_slug' => 'bronze-contributor',
            ],
            [
                'name' => 'Silver Supporter',
                'description' => 'Recognition for cumulative contributions from ₦10 million to ₦99 million.',
                'min_amount' => 10000000,
                'max_amount' => 99999999.99,
                'sort_order' => 3,
                'badge_slug' => 'silver-supporter',
            ],
            [
                'name' => 'Gold Benefactor',
                'description' => 'Recognition for cumulative contributions from ₦100 million to ₦499 million.',
                'min_amount' => 100000000,
                'max_amount' => 499999999.99,
                'sort_order' => 4,
                'badge_slug' => 'gold-benefactor',
            ],
            [
                'name' => 'Platinum Benefactor',
                'description' => 'Recognition for cumulative contributions from ₦500 million to ₦999 million.',
                'min_amount' => 500000000,
                'max_amount' => 999999999.99,
                'sort_order' => 5,
                'badge_slug' => 'platinum-benefactor',
            ],
            [
                'name' => "Founders/Principals' Circle",
                'description' => 'Highest recognition for cumulative contributions of ₦1 billion and above.',
                'min_amount' => 1000000000,
                'max_amount' => null,
                'sort_order' => 6,
                'badge_slug' => 'founders-principals-circle',
            ],
        ];

        $canonicalNames = [];

        foreach ($tiers as $tier) {
            $canonicalNames[] = $tier['name'];

            TierConfiguration::query()->updateOrCreate(
                ['name' => $tier['name']],
                [
                    'description' => $tier['description'],
                    'tier_badge_url' => $baseUrl.'/'.$tier['badge_slug'].'.png',
                    'min_amount' => $tier['min_amount'],
                    'max_amount' => $tier['max_amount'],
                    'benefits' => [],
                    'sort_order' => $tier['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        TierConfiguration::query()
            ->whereNotIn('name', $canonicalNames)
            ->update(['is_active' => false]);
    }
}
