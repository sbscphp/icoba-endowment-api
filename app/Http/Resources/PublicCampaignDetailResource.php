<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use App\Models\GraduationSet;
use Illuminate\Http\Request;

/**
 * @mixin Campaign
 */
class PublicCampaignDetailResource extends PublicCampaignListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('graduationSets:uuid,name,set_number');

        return array_merge(parent::toArray($request), [
            'long_description' => $this->long_description,
            'gallery_images' => is_array($this->gallery_images) ? $this->gallery_images : [],
            'applies_to_all_graduation_sets' => (bool) $this->applies_to_all_graduation_sets,
            'graduation_sets' => $this->graduationSetsPayload(),
        ]);
    }

    /**
     * @return list<array{graduation_set_id: string, name: string, set_number: string}>
     */
    private function graduationSetsPayload(): array
    {
        if ($this->applies_to_all_graduation_sets) {
            return GraduationSet::query()
                ->orderBy('name')
                ->get(['uuid', 'name', 'set_number'])
                ->map(fn (GraduationSet $set): array => [
                    'graduation_set_id' => $set->uuid,
                    'name' => $set->name,
                    'set_number' => $set->set_number,
                ])
                ->values()
                ->all();
        }

        return $this->graduationSets->map(fn (GraduationSet $set): array => [
            'graduation_set_id' => $set->uuid,
            'name' => $set->name,
            'set_number' => $set->set_number,
        ])->values()->all();
    }
}
