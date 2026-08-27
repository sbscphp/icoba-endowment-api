<?php

namespace App\Services\Public;

use App\Models\Ad;
use App\Models\AdSetting;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PublicAdService
{
    /**
     * @return Collection<int, Ad>
     */
    public function listVisible(): Collection
    {
        $now = Carbon::now();

        return Ad::query()
            ->with('images')
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('sort_order')
            ->orderByDesc('starts_at')
            ->get();
    }

    public function transitionSeconds(): int
    {
        return (int) (AdSetting::query()->value('ads_transition_seconds') ?? AdService::DEFAULT_TRANSITION_SECONDS);
    }
}
