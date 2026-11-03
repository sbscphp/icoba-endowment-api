<?php

namespace App\Services\Public;

use App\Enums\AdImageOrientation;
use App\Models\Ad;
use App\Models\AdImage;
use App\Models\AdSetting;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PublicAdService
{
    /**
     * Live ads for the guest site. With an orientation, only ads that have at least one image
     * in that orientation are returned and their `images` relation is loaded pre-filtered to it,
     * so a portrait slot never receives a landscape creative.
     *
     * @return Collection<int, Ad>
     */
    public function listVisible(?AdImageOrientation $orientation = null): Collection
    {
        $now = Carbon::now();

        $query = Ad::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('sort_order')
            ->orderByDesc('starts_at');

        if ($orientation === null) {
            return $query->with('images')->get();
        }

        $forOrientation = function (Builder|HasMany $builder) use ($orientation): void {
            $builder->where('orientation', $orientation->value);
        };

        return $query
            ->whereHas('images', $forOrientation)
            ->with(['images' => $forOrientation])
            ->get();
    }

    public function transitionSeconds(): int
    {
        return (int) (AdSetting::query()->value('ads_transition_seconds') ?? AdService::DEFAULT_TRANSITION_SECONDS);
    }
}
