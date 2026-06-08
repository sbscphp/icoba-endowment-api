<?php

namespace App\Services\Public;

use App\Models\HeroSlide;

class PublicHeroSlideService
{
    public function listActive(): ?HeroSlide
    {
        return HeroSlide::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->first();
    }
}
