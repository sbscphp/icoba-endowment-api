<?php

namespace App\Services\Public;

use App\Models\HeroSlide;
use Illuminate\Support\Collection;

class PublicHeroSlideService
{
    /**
     * @return Collection<int, HeroSlide>
     */
    public function listActive(): Collection
    {
        return HeroSlide::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get([
                'uuid',
                'title',
                'banner_url',
                'primary_cta_url',
                'primary_cta_text',
                'secondary_cta_url',
                'secondary_cta_text',
                'sort_order',
            ]);
    }
}
