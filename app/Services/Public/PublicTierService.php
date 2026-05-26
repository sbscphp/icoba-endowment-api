<?php

namespace App\Services\Public;

use App\Models\TierConfiguration;
use Illuminate\Support\Collection;

class PublicTierService
{
    /**
     * @return Collection<int, TierConfiguration>
     */
    public function listActive(): Collection
    {
        return TierConfiguration::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['uuid', 'slug', 'name', 'base_color', 'min_amount', 'max_amount']);
    }
}
