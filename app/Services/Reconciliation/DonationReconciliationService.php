<?php

namespace App\Services\Reconciliation;

use App\Http\Requests\Concerns\ListingFilterRules;
use App\Enums\GivingIdentitySource;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Donation\BankTransferReferenceService;
use App\Services\Donation\DonorCumulativeTotalService;
use App\Services\GivingIdentity\GivingIdentityResolver;
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
        private readonly BankTransferReferenceService $bankTransferReference,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
        private readonly TransactionFinalizationService $finalizationService,
        private readonly TierResolutionService $tierResolution,
        private readonly DonorCumulativeTotalService $cumulativeTotal,
        private readonly ReconciliationDonorUserService $reconciliationDonorUser,
        private readonly GivingIdentityResolver $givingIdentityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stats(array $validated = []): array
    {
        $dateWindow = ListingFilterRules::resolveDateWindow($validated);
        $start = $dateWindow['start'];
        $end = $dateWindow['end'];

        $bankTransfer = Transaction::query()->where('application_type', TransactionApplicationType::BANK_TRANSFER->value);
        $this->applyCreatedAtRange($bankTransfer, $start, $end);

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
            ->when($start !== null, fn (Builder $q) => $q->where('created_at', '>=', $start))
            ->when($end !== null, fn (Builder $q) => $q->where('created_at', '<=', $end))
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
        } elseif ($status === 'awaiting_payment') {
            $query->where('status', TransactionStatus::PENDING)
                ->whereNull('awaiting_bank_verification_at')
                ->whereNot(function (Builder $b): void {
                    $b->whereNull('bank_transfer_reference')
                        ->where(function (Builder $inner): void {
                            $inner->whereJsonContains('metadata->source', 'fcmb_import')
                                ->orWhereJsonContains('metadata->source', 'fcmb_webhook');
                        });
                });
        } elseif ($status === 'unmatched') {
            $query->where('status', TransactionStatus::PENDING)
                ->whereNull('bank_transfer_reference')
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
     * Manually record a received bank transfer for admin reconciliation.
     *
     * When campaign_uuid or pledge_uuid is supplied, the transaction is finalized in the same request.
     *
     * @param  array{
     *     amount: float|string,
     *     reference_id: string,
     *     bank_key: string,
     *     narration: string,
     *     user_uuid?: ?string,
     *     donor_type?: ?string,
     *     donor_type_uuid?: ?string,
     *     donor_email?: ?string,
     *     donor_phone?: ?string,
     *     firstname?: ?string,
     *     lastname?: ?string,
     *     set_number?: ?string,
     *     alumni_identifier?: ?string,
     *     organization_name?: ?string,
     *     corporate_category_uuid?: ?string,
     *     rc_number?: ?string,
     *     tin?: ?string,
     *     campaign_uuid?: ?string,
     *     pledge_uuid?: ?string,
     *     reconciliation_note?: ?string,
     *     is_anonymous?: bool,
     * }  $payload
     */
    public function createManual(array $payload, string $adminUuid): Transaction
    {
        $amount = (float) $payload['amount'];
        $referenceId = trim((string) $payload['reference_id']);
        $narration = trim((string) $payload['narration']);
        $accountKey = trim((string) $payload['bank_key']);

        $account = $this->bankAccountRegistry->resolveByAccountKey($accountKey);
        if ($account === null) {
            throw ValidationException::withMessages([
                'bank_key' => ['Selected bank account is not configured.'],
            ]);
        }

        if (Transaction::query()->where('fcmb_statement_reference', $referenceId)->exists()) {
            throw ValidationException::withMessages([
                'reference_id' => ['A transaction with this bank reference already exists.'],
            ]);
        }

        $bankTransferReference = $this->bankTransferReference->extractFromNarration($narration)
            ?? $this->bankTransferReference->extractFromNarration($referenceId);

        if ($bankTransferReference === null && $referenceId !== '') {
            $bankTransferReference = mb_substr($referenceId, 0, 48);
        }

        if ($bankTransferReference !== null
            && Transaction::query()->where('bank_transfer_reference', $bankTransferReference)->exists()) {
            throw ValidationException::withMessages([
                'reference_id' => ['A transaction with this bank transfer reference already exists.'],
            ]);
        }

        $paidAt = now();
        $snapshot = $this->transactionNgnSnapshot->resolveAtDate($amount, $account['currency'], $paidAt);

        $transactionId = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'transaction_id',
            'prefix' => 'TRN-',
            'idLength' => 12,
            'idType' => 'numalpha',
        ]);
        if (is_array($transactionId)) {
            $transactionId = 'TRN-'.strtoupper(bin2hex(random_bytes(4)));
        }

        $metadata = [
            'source' => 'admin_manual',
            'narration' => $narration,
            'bank_transaction_date' => $paidAt->toIso8601String(),
            'paid_into_account_number' => $account['account_number'],
            'paid_into_account_key' => $account['account_key'],
        ];

        $campaignUuid = $this->resolveCampaignUuidFromPayload($payload, $account['currency']);
        $pledgeUuid = isset($payload['pledge_uuid']) && trim((string) $payload['pledge_uuid']) !== ''
            ? (string) $payload['pledge_uuid']
            : null;
        $userUuid = isset($payload['user_uuid']) && trim((string) $payload['user_uuid']) !== ''
            ? (string) $payload['user_uuid']
            : null;
        $linkedUser = $userUuid !== null
            ? User::query()
                ->where('uuid', $userUuid)
                ->first(['uuid', 'donor_type_uuid', 'email', 'firstname', 'lastname'])
            : null;
        $pledge = $pledgeUuid !== null
            ? Pledge::query()->where('uuid', $pledgeUuid)->first()
            : null;
        $givingIdentityUuid = $this->resolveGivingIdentityUuid($linkedUser, $pledge);

        $transaction = Transaction::query()->create([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $campaignUuid,
            'pledge_uuid' => $pledgeUuid,
            'user_uuid' => $userUuid,
            'giving_identity_uuid' => $givingIdentityUuid,
            'donor_type_uuid' => $linkedUser?->donor_type_uuid,
            'donor_email' => $linkedUser?->email,
            'donor_name' => $linkedUser !== null
                ? trim(($linkedUser->firstname ?? '').' '.($linkedUser->lastname ?? ''))
                : null,
            'amount' => $amount,
            'currency' => $account['currency'],
            'exchange_rate_to_naira' => $snapshot['exchange_rate_to_naira'],
            'amount_in_naira' => $snapshot['amount_in_naira'],
            'status' => TransactionStatus::PENDING,
            'gateway' => PaymentGateway::Fcmb->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER,
            'paid_into_account_number' => $account['account_number'],
            'fcmb_statement_reference' => $referenceId,
            'narration' => $narration,
            'bank_transfer_reference' => $bankTransferReference,
            'awaiting_bank_verification_at' => $paidAt,
            'is_anonymous' => array_key_exists('is_anonymous', $payload) ? (bool) $payload['is_anonymous'] : false,
            'metadata' => $metadata,
        ]);

        if ($this->hasReconciliationLinkageInput($payload)) {
            $linkage = $this->resolveReconciliationLinkage($payload, $transaction);
            $this->persistReconciliationLinkage(
                $transaction,
                $linkage['user'],
                $linkage['campaign_uuid'],
                $linkage['pledge'],
                $linkage['note'],
                $linkage['is_anonymous'],
            );
            $transaction = $transaction->refresh();

            if ($linkage['campaign_uuid'] !== null || $linkage['pledge'] !== null) {
                $this->finalizationService->finalizeSuccessful($transaction, [
                    'reconciled_by_admin_uuid' => $adminUuid,
                    'reconciliation_note' => $linkage['note'],
                    'metadata' => [
                        'reconciliation_completed_at' => now()->toIso8601String(),
                    ],
                    'tax_receipt_email_meta_key' => 'bank_transfer_tax_receipt_email_queued',
                ]);
            }
        }

        return $this->findQueueItem($transaction->uuid);
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
     *     donor_type?: ?string,
     *     donor_type_uuid?: ?string,
     *     donor_email?: ?string,
     *     donor_phone?: ?string,
     *     firstname?: ?string,
     *     lastname?: ?string,
     *     set_number?: ?string,
     *     alumni_identifier?: ?string,
     *     organization_name?: ?string,
     *     corporate_category_uuid?: ?string,
     *     rc_number?: ?string,
     *     tin?: ?string,
     *     campaign_uuid?: ?string,
     *     pledge_uuid?: ?string,
     *     reconciliation_note?: ?string,
     *     is_anonymous?: bool,
     * }  $payload
     */
    public function completeManual(Transaction $transaction, array $payload, string $adminUuid): Transaction
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            throw new ApiException('Only pending bank transfers can be completed.', 422);
        }

        $linkage = $this->resolveReconciliationLinkage($payload, $transaction);
        $this->persistReconciliationLinkage(
            $transaction,
            $linkage['user'],
            $linkage['campaign_uuid'],
            $linkage['pledge'],
            $linkage['note'],
            $linkage['is_anonymous'],
        );

        $transaction = $transaction->refresh();

        $this->finalizationService->finalizeSuccessful($transaction, [
            'reconciled_by_admin_uuid' => $adminUuid,
            'reconciliation_note' => $linkage['note'],
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
            ->with('donorType:uuid,slug')
            ->where(function (Builder $b) use ($like): void {
                $b->where('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('alumni_identifier', 'like', $like)
                    ->orWhere('organization_name', 'like', $like);
            })
            ->limit(15)
            ->get(['uuid', 'firstname', 'lastname', 'email', 'phone_number', 'alumni_identifier', 'organization_name', 'donor_type_uuid'])
            ->map(fn (User $user): array => [
                'uuid' => $user->uuid,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'organization_name' => $user->organization_name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'alumni_identifier' => $user->alumni_identifier,
                'donor_type' => $user->donorType?->slug,
            ]);
    }

    private function applyCreatedAtRange(Builder $query, Carbon|SupportCarbon|null $start, Carbon|SupportCarbon|null $end): void
    {
        if ($start instanceof Carbon || $start instanceof SupportCarbon) {
            $query->where('created_at', '>=', $start);
        }
        if ($end instanceof Carbon || $end instanceof SupportCarbon) {
            $query->where('created_at', '<=', $end);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldCreateDonorFromProfile(array $payload): bool
    {
        return (isset($payload['donor_type']) && trim((string) $payload['donor_type']) !== '')
            || (isset($payload['donor_type_uuid']) && trim((string) $payload['donor_type_uuid']) !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasReconciliationLinkageInput(array $payload): bool
    {
        foreach ([
            'user_uuid',
            'donor_type',
            'donor_type_uuid',
            'donor_email',
            'donor_phone',
            'firstname',
            'lastname',
            'set_number',
            'alumni_identifier',
            'organization_name',
            'corporate_category_uuid',
            'rc_number',
            'tin',
            'campaign_uuid',
            'pledge_uuid',
            'reconciliation_note',
        ] as $field) {
            if (isset($payload[$field]) && trim((string) $payload[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     user: ?User,
     *     campaign_uuid: ?string,
     *     pledge: ?Pledge,
     *     note: ?string,
     *     is_anonymous: ?bool,
     * }
     */
    private function resolveReconciliationLinkage(array $payload, Transaction $transaction): array
    {
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
        } elseif ($this->shouldCreateDonorFromProfile($payload)) {
            $user = $this->reconciliationDonorUser->createFromProfile($payload);
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

        return [
            'user' => $user,
            'campaign_uuid' => $campaignUuid,
            'pledge' => $pledge,
            'note' => $note,
            'is_anonymous' => array_key_exists('is_anonymous', $payload) ? (bool) $payload['is_anonymous'] : null,
        ];
    }

    private function persistReconciliationLinkage(
        Transaction $transaction,
        ?User $user,
        ?string $campaignUuid,
        ?Pledge $pledge,
        ?string $note = null,
        ?bool $isAnonymous = null,
    ): void {
        DB::transaction(function () use ($transaction, $user, $campaignUuid, $pledge, $note, $isAnonymous): void {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING) {
                throw new ApiException('Only pending bank transfers can be updated.', 422);
            }

            if ($user !== null) {
                $locked->user_uuid = $user->uuid;
                if (blank($locked->donor_type_uuid) && filled($user->donor_type_uuid)) {
                    $locked->donor_type_uuid = $user->donor_type_uuid;
                }
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
                if (blank($locked->donor_type_uuid) && filled($pledge->donor_type_uuid)) {
                    $locked->donor_type_uuid = $pledge->donor_type_uuid;
                }
            }

            $givingIdentityUuid = $this->resolveGivingIdentityUuid($user, $pledge);
            if ($givingIdentityUuid !== null) {
                $locked->giving_identity_uuid = $givingIdentityUuid;
            }

            if ($note !== null) {
                $locked->reconciliation_note = $note;
            }

            if ($isAnonymous !== null) {
                $locked->is_anonymous = $isAnonymous;
            }

            $locked->save();
        });
    }

    private function resolveGivingIdentityUuid(?User $user, ?Pledge $pledge): ?string
    {
        if ($pledge !== null && filled($pledge->giving_identity_uuid)) {
            return (string) $pledge->giving_identity_uuid;
        }

        if ($user === null) {
            return null;
        }

        $resolvedUser = User::query()->where('uuid', $user->uuid)->first();
        if ($resolvedUser === null) {
            return null;
        }

        return $this->givingIdentityResolver
            ->resolveForUser($resolvedUser, GivingIdentitySource::RECONCILIATION)
            ->uuid;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCampaignUuidFromPayload(array $payload, string $currency): string
    {
        $campaignUuid = isset($payload['campaign_uuid']) && trim((string) $payload['campaign_uuid']) !== ''
            ? (string) $payload['campaign_uuid']
            : null;
        $pledgeUuid = isset($payload['pledge_uuid']) && trim((string) $payload['pledge_uuid']) !== ''
            ? (string) $payload['pledge_uuid']
            : null;

        if ($pledgeUuid !== null) {
            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
            if ($pledge === null) {
                throw ValidationException::withMessages(['pledge_uuid' => ['Pledge not found.']]);
            }
            if (strtoupper((string) $pledge->currency) !== strtoupper($currency)) {
                throw ValidationException::withMessages([
                    'pledge_uuid' => ['Pledge currency does not match transaction currency.'],
                ]);
            }
            $campaignUuid = $campaignUuid ?? $pledge->campaign_uuid;
        }

        if ($campaignUuid === null) {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Provide either a campaign or a pledge for reconciliation.'],
            ]);
        }

        return $campaignUuid;
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
