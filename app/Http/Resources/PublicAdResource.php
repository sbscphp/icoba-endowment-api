<?php

namespace App\Http\Resources;

use App\Enums\AdImageOrientation;
use App\Models\Ad;
use App\Models\AdImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class PublicAdResource extends JsonResource
{
    /**
     * When the feed was requested for one orientation, `images` holds only that orientation's
     * gallery (the service already loaded it filtered). Otherwise `images` is the full gallery
     * and the per-orientation lists are given alongside so a page can feed both slot types
     * from a single call. Rotation/randomisation stays client-side; lists keep admin sort order.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orientation = AdImageOrientation::tryFromRequest($request->query('orientation'));

        $payload = [
            'ad_id' => $this->uuid,
            'title' => $this->title,
            'target_url' => $this->target_url,
            'image_interval_seconds' => (int) $this->image_interval_seconds,
            'images' => $this->urls($this->images),
        ];

        if ($orientation !== null) {
            $payload['orientation'] = $orientation->value;

            return $payload;
        }

        foreach (AdImageOrientation::cases() as $case) {
            $payload[$case->payloadKey()] = $this->urls($this->resource->imagesFor($case));
        }

        return $payload;
    }

    /**
     * @param  iterable<int, AdImage>  $images
     * @return list<string>
     */
    private function urls(iterable $images): array
    {
        return collect($images)->pluck('image_url')->values()->all();
    }
}
