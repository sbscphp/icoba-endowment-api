<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($this->resource) ? $this->resource : [];

        return [
            'total_count' => (int) ($data['total_count'] ?? 0),
            'active_count' => (int) ($data['active_count'] ?? 0),
            'completed_count' => (int) ($data['completed_count'] ?? 0),
            'paused_count' => (int) ($data['paused_count'] ?? 0),
            'deactivated_count' => (int) ($data['deactivated_count'] ?? 0),
            'draft_count' => (int) ($data['draft_count'] ?? 0),
        ];
    }
}
