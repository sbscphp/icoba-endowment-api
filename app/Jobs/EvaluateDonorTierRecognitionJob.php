<?php

namespace App\Jobs;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Recognition\DonorRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateDonorTierRecognitionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $transactionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'evaluate-donor-tier-recognition:'.$this->transactionUuid;
    }

    public function handle(DonorRecognitionService $recognitionService): void
    {
        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->first();

        if ($transaction === null || $transaction->status !== TransactionStatus::SUCCESSFUL) {
            return;
        }

        try {
            $issued = $recognitionService->evaluateAfterTransaction($transaction);

            if ($issued !== []) {
                Log::info('Issued donor tier recognitions.', [
                    'transaction_uuid' => $this->transactionUuid,
                    'recognition_uuids' => $issued,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Donor tier recognition evaluation failed: '.$e->getMessage(), [
                'transaction_uuid' => $this->transactionUuid,
            ]);

            throw $e;
        }
    }
}
