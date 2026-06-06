<?php

namespace App\Services\Admin\Campaign;

use App\Models\Campaign;
use App\Services\Admin\Transaction\TransactionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class CampaignDonorService
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(string $campaignId, array $validated): LengthAwarePaginator
    {
        $campaign = $this->campaignService->findCampaign($campaignId);

        return $this->transactionService->list($this->scopedListing($campaign, $validated));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: EloquentCollection<int, \App\Models\Transaction>, 1: bool}
     */
    public function exportCollection(string $campaignId, array $validated): array
    {
        $campaign = $this->campaignService->findCampaign($campaignId);

        return $this->transactionService->exportCollection($this->scopedListing($campaign, $validated));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function scopedListing(Campaign $campaign, array $validated): array
    {
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $filters['campaign_uuid'] = $campaign->uuid;
        $validated['filters'] = $filters;

        return $validated;
    }
}
