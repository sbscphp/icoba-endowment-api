<?php

namespace Database\Seeders;

use App\Models\HeroSlide;
use Illuminate\Database\Seeder;

class HeroSlideSeeder extends Seeder
{
    // php artisan db:seed --class=HeroSlideSeeder

    public function run(): void
    {
        HeroSlide::query()->updateOrCreate(
            ['is_deletable' => false],
            [
                'title' => 'Igbobi College ₦10 Billion Endowment Fund for Legacy and Transformation',
                'banner_url' => "https://res.cloudinary.com/sbsc/image/upload/v1780903265/uploads/images/31476_2026-06-08_1780903258.webp",
                'primary_cta_url' => 'http://icoba-alumni-endowment-program-2026',
                'primary_cta_text' => 'Donate Now',
                'secondary_cta_url' => 'http://icoba-alumni-endowment-program',
                'secondary_cta_text' => 'Contribute Today',
                'sort_order' => 0,
                'is_active' => true,
            ]
        );
    }
}
