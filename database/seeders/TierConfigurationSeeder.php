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
                'base_color' => '#64748B',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780082977/tier-badges/67186_2026-05-29_1780082976.png',
            ],
            [
                'name' => 'Bronze Contributor',
                'description' => 'Recognition for cumulative contributions from ₦1 million to ₦9 million.',
                'min_amount' => 1000000,
                'max_amount' => 9999999.99,
                'sort_order' => 2,
                'badge_slug' => 'bronze-contributor',
                'base_color' => '#CD7F32',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780082901/tier-badges/31533_2026-05-29_1780082901.png',
            ],
            [
                'name' => 'Silver Supporter',
                'description' => 'Recognition for cumulative contributions from ₦10 million to ₦99 million.',
                'min_amount' => 10000000,
                'max_amount' => 99999999.99,
                'sort_order' => 3,
                'badge_slug' => 'silver-supporter',
                'base_color' => '#C0C0C0',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780082616/tier-badges/39338_2026-05-29_1780082615.png',
            ],
            [
                'name' => 'Gold Benefactor',
                'description' => 'Recognition for cumulative contributions from ₦100 million to ₦499 million.',
                'min_amount' => 100000000,
                'max_amount' => 499999999.99,
                'sort_order' => 4,
                'badge_slug' => 'gold-benefactor',
                'base_color' => '#FFD700',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780083139/tier-badges/37434_2026-05-29_1780083138.png',
            ],
            [
                'name' => 'Platinum Benefactor',
                'description' => 'Recognition for cumulative contributions from ₦500 million to ₦999 million.',
                'min_amount' => 500000000,
                'max_amount' => 999999999.99,
                'sort_order' => 5,
                'badge_slug' => 'platinum-benefactor',
                'base_color' => '#E5E4E2',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780083255/tier-badges/14762_2026-05-29_1780083254.png',
            ],
            [
                'name' => "Founders/Principals' Circle",
                'description' => 'Highest recognition for cumulative contributions of ₦1 billion and above.',
                'min_amount' => 1000000000,
                'max_amount' => null,
                'sort_order' => 6,
                'badge_slug' => 'founders-principals-circle',
                'base_color' => '#7C3AED',
                'tier_badge_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780083455/tier-badges/28269_2026-05-29_1780083454.png',
            ],
        ];

        $canonicalNames = [];

        foreach ($tiers as $tier) {
            $canonicalNames[] = $tier['name'];

            TierConfiguration::query()->updateOrCreate(
                ['name' => $tier['name']],
                [
                    'slug' => $tier['badge_slug'],
                    'description' => $tier['description'],
                    'tier_badge_url' => $baseUrl.'/'.$tier['badge_slug'].'.png',
                    'base_color' => $tier['base_color'],
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
