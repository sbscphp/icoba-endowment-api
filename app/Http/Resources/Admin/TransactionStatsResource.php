<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionStatsResource extends JsonResource
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
            'successful_count' => (int) ($data['successful_count'] ?? 0),
            'pending_count' => (int) ($data['pending_count'] ?? 0),
            'failed_count' => (int) ($data['failed_count'] ?? 0),
            'reversed_count' => (int) ($data['reversed_count'] ?? 0),
            'superseded_count' => (int) ($data['superseded_count'] ?? 0),
            'anonymous_count' => (int) ($data['anonymous_count'] ?? 0),
            'unique_donors_count' => (int) ($data['unique_donors_count'] ?? 0),
            'total_volume_naira' => (string) ($data['total_volume_naira'] ?? '0'),
        ];
    }
}
