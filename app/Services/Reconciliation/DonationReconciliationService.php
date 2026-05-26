<?php

namespace App\Services\Reconciliation;

use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Donation\DonorCumulativeTotalService;
use App\Services\Tier\TierResolutionService;
use App\Services\Transaction\TransactionFinalizationService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonationReconciliationService
{
    public function __construct(
        private readonly BankAccountRegistry $bankAccountRegistry,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
        private readonly TransactionFinalizationService $finalizationService,
        private readonly TierResolutionService $tierResolution,
        private readonly DonorCumulativeTotalService $cumulativeTotal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $bankTransfer = Transaction::query()->where('application_type', TransactionApplicationType::BANK_TRANSFER->value);

        $totalCount = (clone $bankTransfer)->count();
        $totalValueNgn = (float) (clone $bankTransfer)->sum('amount_in_naira');

        $reconciledQuery = (clone $bankTransfer)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->whereNotNull('reconciled_at');

        $reconciledCount = (clone $reconciledQuery)->count();
        $reconciledValueNgn = (float) (clone $reconciledQuery)->sum('amount_in_naira');

        $queueCount = (clone $bankTransfer)
            ->where('status', TransactionStatus::PENDING)
            ->count();

        $awaitingVerification = (clone $bankTransfer)
            ->where('status', TransactionStatus::PENDING)
            ->whereNotNull('awaiting_bank_verification_at')
            ->count();

        $unmatchedImports = Transaction::query()
            ->where('application_type', TransactionApplicationType::BANK_TRANSFER->value)
            ->where('status', TransactionStatus::PENDING)
            ->where(function (Builder $b): void {
                $b->whereJsonContains('metadata->source', 'fcmb_import')
                    ->orWhereJsonContains('metadata->source', 'fcmb_webhook');
            })
            ->count();

        return [
            'total_count' => $totalCount,
            'total_value_ngn' => (string) round($totalValueNgn, 2),
            'reconciled_count' => $reconciledCount,
            'reconciled_value_ngn' => (string) round($reconciledValueNgn, 2),
            'queue_count' => $queueCount,
            // 'awaiting_verification_count' => $awaitingVerification,
            // 'unmatched_imports_count' => $unmatchedImports,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function queue(array $filters): LengthAwarePaginator
    {
        $status = isset($filters['filters']['reconciliation_status'])
            ? (string) $filters['filters']['reconciliation_status']
            : null;

        $query = Transaction::query()
            ->where('application_type', TransactionApplicationType::BANK_TRANSFER->value)
            ->with(['campaign', 'pledge', 'donor', 'reconciledByAdmin']);

        if ($status === 'reconciled') {
            $query->where('status', TransactionStatus::SUCCESSFUL)
                ->whereNotNull('reconciled_at');
        } elseif ($status === 'awaiting_verification') {
            $query->where('status', TransactionStatus::PENDING)
                ->whereNotNull('awaiting_bank_verification_at');
        } elseif ($status === 'unmatched') {
            $query->where('status', TransactionStatus::PENDING)
                ->where(function (Builder $b): void {
                    $b->whereJsonContains('metadata->source', 'fcmb_import')
                        ->orWhereJsonContains('metadata->source', 'fcmb_webhook');
                });
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $b) use ($like): void {
                $b->where('bank_transfer_reference', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('fcmb_statement_reference', 'like', $like);
            });
        }

        $start = $filters['date_range']['start'] ?? null;
        $end = $filters['date_range']['end'] ?? null;
        if ($start instanceof Carbon || $start instanceof SupportCarbon) {
            $query->where('created_at', '>=', $start);
        }
        if ($end instanceof Carbon || $end instanceof SupportCarbon) {
            $query->where('created_at', '<=', $end);
        }

        $sortBy = isset($filters['sort_by']) ? (string) $filters['sort_by'] : 'created_at';
        $sortDirection = isset($filters['sort_direction']) ? strtolower((string) $filters['sort_direction']) : 'desc';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 25;
        $perPage = max(1, min(100, $perPage));

        return $query
            ->orderBy(in_array($sortBy, ['created_at', 'reconciled_at', 'paid_at', 'amount'], true) ? $sortBy : 'created_at', $sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage);
    }

    public function findQueueItem(string $uuid): Transaction
    {
        $tx = Transaction::query()
            ->where('uuid', $uuid)
            ->with(['campaign', 'pledge', 'donor', 'reconciledByAdmin', 'certificates'])
            ->first();

        if ($tx === null) {
            throw new ApiException('Reconciliation transaction not found.', 404);
        }

        return $tx;
    }

    /**
     * @return array<string, mixed>
     */
    public function tierPreview(Transaction $transaction): array
    {
        $amountNgn = $transaction->amount_in_naira !== null ? (float) $transaction->amount_in_naira : null;
        $singleTier = $this->tierResolution->resolveTierForAmount($amountNgn);

        $donorKey = $this->cumulativeTotal->resolveDonorKeyFromTransaction($transaction);
        $cumulativeNgn = $this->cumulativeTotal->cumulativeTotalNgnForDonorKey($donorKey);
        $qualifiedTiers = $this->tierResolution->resolveQualifiedTiersForCumulativeAmount($cumulativeNgn);

        return [
            'amount_in_naira' => $amountNgn !== null ? (string) $amountNgn : null,
            'single_donation_tier' => $singleTier !== null ? [
                'tier_uuid' => $singleTier->uuid,
                'name' => $singleTier->name,
                'tier_badge_url' => $singleTier->tier_badge_url ?? null,
            ] : null,
            'cumulative_amount_in_naira' => (string) round($cumulativeNgn, 2),
            'qualified_cumulative_tiers' => $qualifiedTiers
                ->map(fn ($tier) => [
                    'tier_uuid' => $tier->uuid,
                    'name' => $tier->name,
                    'tier_badge_url' => $tier->tier_badge_url ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Update the receiving bank account on a pending transaction. Recomputes NGN snapshot at the
     * effective rate date so the amount in naira reflects the historical FX rate.
     *
     * @param  array{paid_into_account_number?: ?string, paid_into_account_key?: ?string}  $payload
     */
    public function updateBankAccount(Transaction $transaction, array $payload): Transaction
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            throw new ApiException('Bank account can only be updated while the transaction is pending.', 422);
        }

        $accountNumber = isset($payload['paid_into_account_number']) && $payload['paid_into_account_number'] !== ''
            ? trim((string) $payload['paid_into_account_number'])
            : null;
        $accountKey = isset($payload['paid_into_account_key']) && $payload['paid_into_account_key'] !== ''
            ? trim((string) $payload['paid_into_account_key'])
            : null;

        $account = null;
        if ($accountNumber !== null) {
            $account = $this->bankAccountRegistry->resolveByAccountNumber($accountNumber);
        } elseif ($accountKey !== null) {
            $account = $this->bankAccountRegistry->resolveByAccountKey($accountKey);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'paid_into_account_number' => ['The selected ICOBA bank account is not recognized.'],
            ]);
        }

        if ($transaction->pledge_uuid !== null) {
            $pledge = Pledge::query()->where('uuid', $transaction->pledge_uuid)->first();
            if ($pledge !== null && strtoupper((string) $pledge->currency) !== $account['currency']) {
                throw ValidationException::withMessages([
                    'paid_into_account_number' => [
                        'Selected account currency does not match the linked pledge currency ('.$pledge->currency.').',
                    ],
                ]);
            }
        }

        return DB::transaction(function () use ($transaction, $account): Transaction {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING) {
                throw new ApiException('Bank account can only be updated while the transaction is pending.', 422);
            }

            $rateDate = $locked->paid_at
                ?? $this->paidAtFromMetadata($locked)
                ?? $locked->created_at
                ?? now();

            $snapshot = $this->transactionNgnSnapshot->resolveAtDate(
                (float) $locked->amount,
                $account['currency'],
                $rateDate,
            );

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['paid_into_account_number'] = $account['account_number'];
            $metadata['paid_into_account_key'] = $account['account_key'];

            $locked->forceFill([
                'paid_into_account_number' => $account['account_number'],
                'gateway' => PaymentGateway::Fcmb->value,
                'currency' => $account['currency'],
                'exchange_rate_to_naira' => $snapshot['exchange_rate_to_naira'],
                'amount_in_naira' => $snapshot['amount_in_naira'],
                'metadata' => $metadata,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array{
     *     user_uuid?: ?string,
     *     campaign_uuid?: ?string,
     *     pledge_uuid?: ?string,
     *     reconciliation_note?: ?string,
     * }  $payload
     */
    public function completeManual(Transaction $transaction, array $payload, string $adminUuid): Transaction
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            throw new ApiException('Only pending bank transfers can be completed.', 422);
        }

        $userUuid = isset($payload['user_uuid']) && $payload['user_uuid'] !== '' ? (string) $payload['user_uuid'] : null;
        $campaignUuid = isset($payload['campaign_uuid']) && $payload['campaign_uuid'] !== '' ? (string) $payload['campaign_uuid'] : null;
        $pledgeUuid = isset($payload['pledge_uuid']) && $payload['pledge_uuid'] !== '' ? (string) $payload['pledge_uuid'] : null;
        $note = isset($payload['reconciliation_note']) && $payload['reconciliation_note'] !== '' ? (string) $payload['reconciliation_note'] : null;

        $user = null;
        if ($userUuid !== null) {
            $user = User::query()->where('uuid', $userUuid)->first();
            if ($user === null) {
                throw ValidationException::withMessages(['user_uuid' => ['Donor not found.']]);
            }
        }

        $pledge = null;
        if ($pledgeUuid !== null) {
            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
            if ($pledge === null) {
                throw ValidationException::withMessages(['pledge_uuid' => ['Pledge not found.']]);
            }
            if (strtoupper((string) $pledge->currency) !== strtoupper((string) $transaction->currency)) {
                throw ValidationException::withMessages([
                    'pledge_uuid' => ['Pledge currency does not match transaction currency.'],
                ]);
            }
        }

        DB::transaction(function () use ($transaction, $user, $campaignUuid, $pledge): void {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING) {
                throw new ApiException('Only pending bank transfers can be completed.', 422);
            }

            if ($user !== null) {
                $locked->user_uuid = $user->uuid;
                if (blank($locked->donor_email) && filled($user->email)) {
                    $locked->donor_email = $user->email;
                }
                if (blank($locked->donor_name) && (filled($user->firstname) || filled($user->lastname))) {
                    $locked->donor_name = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));
                }
            }

            if ($campaignUuid !== null) {
                $locked->campaign_uuid = $campaignUuid;
            }

            if ($pledge !== null) {
                $locked->pledge_uuid = $pledge->uuid;
                $locked->campaign_uuid = $locked->campaign_uuid ?? $pledge->campaign_uuid;
            }

            $locked->save();
        });

        $transaction = $transaction->refresh();

        $this->finalizationService->finalizeSuccessful($transaction, [
            'reconciled_by_admin_uuid' => $adminUuid,
            'reconciliation_note' => $note,
            'metadata' => [
                'reconciliation_completed_at' => now()->toIso8601String(),
            ],
            'tax_receipt_email_meta_key' => 'bank_transfer_tax_receipt_email_queued',
        ]);

        return $this->findQueueItem($transaction->uuid);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function searchDonors(string $query): \Illuminate\Support\Collection
    {
        $query = trim($query);
        if ($query === '' || strlen($query) < 2) {
            return collect();
        }

        $like = '%'.$query.'%';

        return User::query()
            ->where(function (Builder $b) use ($like): void {
                $b->where('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('alumni_identifier', 'like', $like)
                    ->orWhere('organization_name', 'like', $like);
            })
            ->limit(15)
            ->get(['uuid', 'firstname', 'lastname', 'email', 'phone_number', 'alumni_identifier', 'organization_name'])
            ->map(fn (User $user): array => [
                'uuid' => $user->uuid,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'organization_name' => $user->organization_name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'alumni_identifier' => $user->alumni_identifier,
            ]);
    }

    private function paidAtFromMetadata(Transaction $transaction): ?Carbon
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

        $candidates = [
            $metadata['bank_transaction_date'] ?? null,
            $metadata['fcmb_transaction_date'] ?? null,
        ];

        foreach ($candidates as $candidate) {
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
