<?php

namespace App\Services\Reconciliation;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\DonorRecognition;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\TransactionReceipt;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Public\PublicEndowmentStatsService;
use App\Services\Transaction\TransactionFinalizationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AdminManualReconciliationBackfillService
{
    public function __construct(
        private readonly TransactionFinalizationService $finalizer,
        private readonly AdminManualReconciliationDonorResolver $donorResolver,
        private readonly PledgeBalanceService $pledgeBalance,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     cleared_awaiting_verification: int,
     *     donor_backfilled: int,
     *     finalized: int,
     *     deleted: int,
     *     unchanged: int,
     *     preview: list<array{transaction_id: string, uuid: string, changes: list<string>}>,
     * }
     */
    public function run(
        bool $dryRun = true,
        bool $finalizeLinked = true,
        bool $suppressEmails = false,
        int $chunkSize = 100,
        ?string $transactionUuid = null,
        int $previewLimit = 50,
    ): array {
        $stats = [
            'scanned' => 0,
            'cleared_awaiting_verification' => 0,
            'donor_backfilled' => 0,
            'finalized' => 0,
            'deleted' => 0,
            'unchanged' => 0,
            'preview' => [],
        ];

        $this->baseQuery($transactionUuid)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (
                $dryRun,
                $finalizeLinked,
                $suppressEmails,
                $previewLimit,
                &$stats,
            ): void {
                foreach ($rows as $transaction) {
                    $stats['scanned']++;

                    $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                    $draft = is_array($metadata['reconciliation_draft'] ?? null)
                        ? $metadata['reconciliation_draft']
                        : [];

                    $donorUpdates = $this->donorResolver->resolveDonorUpdates($transaction, $draft);
                    $traceable = $this->donorResolver->canTraceDonor($transaction, $draft, $donorUpdates);
                    $changes = [];

                    if (! $traceable) {
                        $changes[] = 'delete';
                        $stats['deleted']++;

                        if (count($stats['preview']) < $previewLimit) {
                            $stats['preview'][] = [
                                'transaction_id' => (string) $transaction->transaction_id,
                                'uuid' => (string) $transaction->uuid,
                                'changes' => $changes,
                            ];
                        }

                        if (! $dryRun) {
                            $this->purgeTransaction($transaction);
                        }

                        continue;
                    }

                    if ($transaction->awaiting_bank_verification_at !== null) {
                        $changes[] = 'awaiting_bank_verification_at';
                        $stats['cleared_awaiting_verification']++;
                    }

                    if ($donorUpdates !== []) {
                        $changes = array_merge($changes, array_keys($donorUpdates));
                        $stats['donor_backfilled']++;
                    }

                    $shouldFinalize = $finalizeLinked && $this->shouldFinalizeLinked($transaction);

                    if ($shouldFinalize) {
                        $changes[] = 'finalize';
                        $stats['finalized']++;
                    }

                    if ($changes === []) {
                        $stats['unchanged']++;

                        continue;
                    }

                    if (count($stats['preview']) < $previewLimit) {
                        $stats['preview'][] = [
                            'transaction_id' => (string) $transaction->transaction_id,
                            'uuid' => (string) $transaction->uuid,
                            'changes' => $changes,
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    if ($transaction->awaiting_bank_verification_at !== null || $donorUpdates !== []) {
                        DB::transaction(function () use ($transaction, $donorUpdates): void {
                            $updates = $donorUpdates;
                            if ($transaction->awaiting_bank_verification_at !== null) {
                                $updates['awaiting_bank_verification_at'] = null;
                            }

                            $transaction->forceFill($updates)->save();
                        });

                        $transaction = $transaction->refresh();
                    }

                    if ($shouldFinalize) {
                        $paidAt = $this->paidAtFromMetadata($transaction) ?? $transaction->created_at;

                        $finalized = $this->finalizer->finalizeSuccessful($transaction, [
                            'paid_at' => $paidAt,
                            'reconciliation_note' => $transaction->reconciliation_note,
                            'metadata' => [
                                'reconciliation_backfill_at' => now()->toIso8601String(),
                            ],
                            'tax_receipt_email_meta_key' => 'bank_transfer_tax_receipt_email_queued',
                            'suppress_emails' => $suppressEmails,
                        ]);

                        if (! $finalized) {
                            $stats['finalized']--;
                        }
                    }
                }
            });

        return $stats;
    }

    private function baseQuery(?string $transactionUuid = null): Builder
    {
        $query = Transaction::query()
            ->where('application_type', TransactionApplicationType::BANK_TRANSFER)
            ->where('metadata->source', 'admin_manual');

        if ($transactionUuid !== null && trim($transactionUuid) !== '') {
            $query->where('uuid', trim($transactionUuid));
        }

        return $query;
    }

    private function shouldFinalizeLinked(Transaction $transaction): bool
    {
        return $transaction->status === TransactionStatus::PENDING
            && $transaction->reconciled_at === null
            && (filled($transaction->campaign_uuid) || filled($transaction->pledge_uuid));
    }

    private function purgeTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $pledgeUuid = $transaction->pledge_uuid;

            TransactionReceipt::query()
                ->where('transaction_uuid', $transaction->uuid)
                ->delete();

            DonorRecognition::query()
                ->where('trigger_transaction_uuid', $transaction->uuid)
                ->delete();

            $transaction->delete();

            if ($pledgeUuid !== null) {
                $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
                if ($pledge !== null) {
                    $this->pledgeBalance->syncPledgeStatus($pledge);
                }
            }
        });

        PublicEndowmentStatsService::forgetCache();
    }

    private function paidAtFromMetadata(Transaction $transaction): ?Carbon
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

        foreach ([
            $metadata['bank_transaction_date'] ?? null,
            $metadata['fcmb_transaction_date'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
