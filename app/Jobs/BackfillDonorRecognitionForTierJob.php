<?php

namespace App\Jobs;

use App\Models\TierConfiguration;
use App\Services\Recognition\DonorRecognitionBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillDonorRecognitionForTierJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $tierUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'backfill-donor-recognition-tier:'.$this->tierUuid;
    }

    public function handle(DonorRecognitionBackfillService $backfillService): void
    {
        $tier = TierConfiguration::query()
            ->where('uuid', $this->tierUuid)
            ->where('is_active', true)
            ->first();

        if ($tier === null) {
            return;
        }

        try {
            $result = $backfillService->backfillTier($tier, dryRun: false);

            Log::info('Donor recognition tier backfill completed.', $result);
        } catch (\Throwable $e) {
            Log::warning('Donor recognition tier backfill failed: '.$e->getMessage(), [
                'tier_uuid' => $this->tierUuid,
            ]);

            throw $e;
        }
    }
}
