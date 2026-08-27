<?php

namespace App\Http\Resources\Admin;

use App\Models\AdSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdSetting
 */
class AdSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('updater:uuid,name,email');

        return [
            'ads_transition_seconds' => (int) $this->ads_transition_seconds,
            'updated_by' => $this->updater !== null ? [
                'admin_id' => $this->updater->uuid,
                'name' => $this->updater->name,
                'email' => $this->updater->email,
            ] : null,
            'updated_at' => $this->updated_at,
        ];
    }
}
