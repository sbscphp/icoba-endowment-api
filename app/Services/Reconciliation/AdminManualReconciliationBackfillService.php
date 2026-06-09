<?php

namespace App\Services\Reconciliation;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\GivingIdentity;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transaction\TransactionFinalizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AdminManualReconciliationBackfillService
{
    public function __construct(
        private readonly TransactionFinalizationService $finalizer,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     cleared_awaiting_verification: int,
     *     donor_backfilled: int,
     *     finalized: int,
     *     unchanged: int,
     *     preview: list<array{transaction_id: string, uuid: string, changes: list<string>}>,
     * }
     */
    public function run(
        bool $dryRun = true,
        bool $finalizeLinked = false,
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

                    $updates = [];

                    if ($transaction->awaiting_bank_verification_at !== null) {
                        $updates['awaiting_bank_verification_at'] = null;
                        $stats['cleared_awaiting_verification']++;
                    }

                    $donorUpdates = $this->resolveDonorUpdates($transaction, $draft);
                    if ($donorUpdates !== []) {
                        $updates = array_merge($updates, $donorUpdates);
                        $stats['donor_backfilled']++;
                    }

                    if ($updates === []) {
                        $stats['unchanged']++;

                        continue;
                    }

                    if (count($stats['preview']) < $previewLimit) {
                        $stats['preview'][] = [
                            'transaction_id' => (string) $transaction->transaction_id,
                            'uuid' => (string) $transaction->uuid,
                            'changes' => array_keys($updates),
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($transaction, $updates): void {
                        $transaction->forceFill($updates)->save();
                    });
                }
            });

        if (! $dryRun && $finalizeLinked) {
            $this->baseQuery($transactionUuid)
                ->where('status', TransactionStatus::PENDING)
                ->whereNull('reconciled_at')
                ->where(function (Builder $query): void {
                    $query->whereNotNull('campaign_uuid')
                        ->orWhereNotNull('pledge_uuid');
                })
                ->orderBy('id')
                ->chunkById($chunkSize, function ($rows) use ($suppressEmails, &$stats): void {
                    foreach ($rows as $transaction) {
                        $finalized = $this->finalizer->finalizeSuccessful($transaction->refresh(), [
                            'reconciliation_note' => $transaction->reconciliation_note,
                            'metadata' => [
                                'reconciliation_backfill_at' => now()->toIso8601String(),
                            ],
                            'tax_receipt_email_meta_key' => 'bank_transfer_tax_receipt_email_queued',
                            'suppress_emails' => $suppressEmails,
                        ]);

                        if ($finalized) {
                            $stats['finalized']++;
                        }
                    }
                });
        }

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

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function resolveDonorUpdates(Transaction $transaction, array $draft): array
    {
        if ($draft === []) {
            return [];
        }

        $resolved = [];

        $identityUuid = trim((string) ($draft['user_identity'] ?? $draft['giving_identity_uuid'] ?? ''));
        $userUuid = trim((string) ($draft['user_uuid'] ?? ''));
        $user = $userUuid !== ''
            ? User::query()->where('uuid', $userUuid)->first()
            : null;
        $identity = $identityUuid !== ''
            ? GivingIdentity::query()->with('user')->where('uuid', $identityUuid)->first()
            : null;

        if ($user !== null) {
            $resolved = [
                'user_uuid' => $user->uuid,
                'donor_email' => $user->email,
                'donor_phone' => $user->phone_number,
                'donor_name' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: null,
                'donor_type_uuid' => $user->donor_type_uuid,
            ];
        } elseif ($identity !== null) {
            $resolved = [
                'giving_identity_uuid' => $identity->uuid,
                'user_uuid' => $identity->user_uuid,
                'donor_email' => $identity->user?->email ?? $identity->email_lower,
                'donor_phone' => $identity->user?->phone_number,
                'donor_name' => filled($identity->organization_name)
                    ? trim((string) $identity->organization_name)
                    : (trim(trim((string) ($identity->firstname ?? '')).' '.trim((string) ($identity->lastname ?? ''))) ?: null),
                'donor_type_uuid' => $identity->donor_type_uuid,
            ];
        } else {
            $organizationName = trim((string) ($draft['organization_name'] ?? ''));
            $personName = trim(trim((string) ($draft['firstname'] ?? '')).' '.trim((string) ($draft['lastname'] ?? '')));

            $resolved = [
                'donor_email' => isset($draft['donor_email']) ? strtolower(trim((string) $draft['donor_email'])) : null,
                'donor_phone' => isset($draft['donor_phone']) ? trim((string) $draft['donor_phone']) : null,
                'donor_name' => $organizationName !== '' ? $organizationName : ($personName !== '' ? $personName : null),
            ];
        }

        if (filled($draft['campaign_uuid'] ?? null)) {
            $resolved['campaign_uuid'] = (string) $draft['campaign_uuid'];
        }
        if (filled($draft['pledge_uuid'] ?? null)) {
            $resolved['pledge_uuid'] = (string) $draft['pledge_uuid'];
        }

        $updates = [];
        foreach ($resolved as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $transaction->{$field};
            if ($current === null || $current === '') {
                $updates[$field] = $value;
            }
        }

        return $updates;
    }
}
