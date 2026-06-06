<?php

namespace App\Services\Admin\Pledge;

use App\Enums\Currency;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PledgeService
{
    public function __construct(
        private readonly PledgeBalanceService $balanceService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
        $query = Pledge::query()->with([
            'campaign:uuid,name,campaign_id',
            'donor:uuid,firstname,lastname,email,phone_number',
        ]);

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('campaign_uuid', $campaignUuid);
        }

        $userUuid = data_get($validated, 'filters.user_uuid');
        if (is_string($userUuid) && $userUuid !== '') {
            $query->where('user_uuid', $userUuid);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Pledges for a single donor account (customer); ignores any user_uuid filter in $validated.
     *
     * @param  array<string, mixed>  $validated
     */
    public function listForUser(string $userUuid, array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
        $query = Pledge::query()->with([
            'campaign:uuid,name,campaign_id',
            'donor:uuid,firstname,lastname,email,phone_number',
        ])->where('user_uuid', $userUuid);

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('campaign_uuid', $campaignUuid);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Aggregated pledge metrics for admin dashboards (optionally filtered by date and filters.*).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stats(array $validated): array
    {
        $base = $this->statsBaseQuery($validated);

        $byCurrency = $this->committedTotalsByCurrencyRows($base);

        return [
            'total_pledges' => (clone $base)->count(),
            'active_count' => (clone $base)->where('status', PledgeStatus::ACTIVE)->count(),
            'fulfilled_count' => (clone $base)->where('status', PledgeStatus::FULFILLED)->count(),
            'cancelled_count' => (clone $base)->where('status', PledgeStatus::CANCELLED)->count(),
            'with_user_account_count' => (clone $base)->whereNotNull('user_uuid')->count(),
            'guest_pledge_count' => (clone $base)->whereNull('user_uuid')->count(),
            'anonymous_count' => (clone $base)->where('is_anonymous', true)->count(),
            'non_anonymous_count' => (clone $base)->where('is_anonymous', false)->count(),
            'guest_anonymous_count' => (clone $base)
                ->whereNull('user_uuid')
                ->where('is_anonymous', true)
                ->count(),
            'anonymous_with_user_account_count' => (clone $base)
                ->whereNotNull('user_uuid')
                ->where('is_anonymous', true)
                ->count(),
            'committed_total_ngn' => $this->sumCommittedNgnAggregate($base),
            'committed_totals_by_currency' => $byCurrency,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<Pledge>
     */
    private function statsBaseQuery(array $validated): Builder
    {
        $query = Pledge::query();

        $start = ! empty($validated['start_date']) ? Carbon::parse((string) $validated['start_date'])->startOfDay() : null;
        $end = ! empty($validated['end_date']) ? Carbon::parse((string) $validated['end_date'])->endOfDay() : null;

        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('campaign_uuid', $campaignUuid);
        }

        $userUuid = data_get($validated, 'filters.user_uuid');
        if (is_string($userUuid) && $userUuid !== '') {
            $query->where('user_uuid', $userUuid);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('currency', strtoupper($currency));
        }

        $plan = data_get($validated, 'filters.payment_plan_type');
        if (is_string($plan) && $plan !== '') {
            $query->where('payment_plan_type', $plan);
        }

        $anonymous = data_get($validated, 'filters.is_anonymous');
        if ($anonymous !== null && $anonymous !== '') {
            $truthy = in_array($anonymous, ['1', 1, true, 'true'], true);
            $query->where('is_anonymous', $truthy);
        }

        return $query;
    }

    /**
     * Like stats(), but restricted to pledges owned by $userUuid.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function statsForUser(string $userUuid, array $validated): array
    {
        $base = $this->statsBaseQuery($validated)->where('user_uuid', $userUuid);

        $byCurrency = $this->committedTotalsByCurrencyRows($base);

        return [
            'total_pledges' => (clone $base)->count(),
            'active_count' => (clone $base)->where('status', PledgeStatus::ACTIVE)->count(),
            'fulfilled_count' => (clone $base)->where('status', PledgeStatus::FULFILLED)->count(),
            'cancelled_count' => (clone $base)->where('status', PledgeStatus::CANCELLED)->count(),
            'with_user_account_count' => (clone $base)->whereNotNull('user_uuid')->count(),
            'guest_pledge_count' => (clone $base)->whereNull('user_uuid')->count(),
            'anonymous_count' => (clone $base)->where('is_anonymous', true)->count(),
            'non_anonymous_count' => (clone $base)->where('is_anonymous', false)->count(),
            'guest_anonymous_count' => (clone $base)
                ->whereNull('user_uuid')
                ->where('is_anonymous', true)
                ->count(),
            'anonymous_with_user_account_count' => (clone $base)
                ->whereNotNull('user_uuid')
                ->where('is_anonymous', true)
                ->count(),
            'committed_total_ngn' => $this->sumCommittedNgnAggregate($base),
            'committed_totals_by_currency' => $byCurrency,
        ];
    }

    /**
     * @return array{pledge: Pledge, fulfilled_amount: string, remaining_amount: string, ledger: LengthAwarePaginator}
     */
    public function detailWithLedgerForUser(string $userUuid, string $pledgeUuid, int $perPage = 15): array
    {
        $owned = Pledge::query()
            ->where('uuid', $pledgeUuid)
            ->where('user_uuid', $userUuid)
            ->exists();

        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(Pledge::class, [$pledgeUuid]);
        }

        return $this->detailWithLedger($pledgeUuid, $perPage);
    }

    public function findByUuid(string $uuid): Pledge
    {
        $pledge = Pledge::query()
            ->where('uuid', $uuid)
            ->with([
                'campaign:uuid,name,campaign_id',
                'donor:uuid,firstname,lastname,email,graduation_set_uuid,donor_type_uuid',
                'donor.graduationSet:uuid,name,set_number',
                'donor.donorType:uuid,slug,label',
            ])
            ->first();

        if ($pledge === null) {
            throw (new ModelNotFoundException)->setModel(Pledge::class, [$uuid]);
        }

        return $pledge;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPledge(array $data): Pledge
    {
        $pledge = Pledge::query()->create($data);

        return $pledge->fresh(['campaign', 'donor']);
    }

    /**
     * Optional pending placeholder transaction for pledge payment expectation.
     */
    public function createPlaceholderTransaction(Pledge $pledge, float $amount, string $gateway = 'pledge_placeholder'): Transaction
    {
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

        return Transaction::query()->create([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $pledge->campaign_uuid,
            'pledge_uuid' => $pledge->uuid,
            'user_uuid' => $pledge->user_uuid,
            'giving_identity_uuid' => $pledge->giving_identity_uuid,
            'donor_type_uuid' => $pledge->donor_type_uuid,
            'donor_name' => $pledge->donor_name,
            'donor_email' => $pledge->donor_email,
            'donor_phone' => $pledge->donor_phone,
            'is_anonymous' => $pledge->is_anonymous,
            'amount' => $amount,
            'currency' => $pledge->currency,
            'amount_in_naira' => null,
            'status' => TransactionStatus::PENDING,
            'gateway' => $gateway,
            'application_type' => TransactionApplicationType::PLEDGE_PLACEHOLDER,
        ]);
    }

    /**
     * @return array{pledge: Pledge, fulfilled_amount: string, remaining_amount: string, ledger: LengthAwarePaginator}
     */
    public function detailWithLedger(string $uuid, int $perPage = 15): array
    {
        $pledge = $this->findByUuid($uuid);
        $fulfilled = $this->balanceService->fulfilledAmount($pledge);
        $remaining = $this->balanceService->remainingAmount($pledge);

        $perPage = max(1, min($perPage, 100));

        $ledger = Transaction::query()
            ->where('pledge_uuid', $pledge->uuid)
            ->where('status', '!=', TransactionStatus::SUPERSEDED)
            ->with(['campaign:uuid,name', 'donor:uuid,firstname,lastname,email,phone_number', 'pledge:uuid,committed_amount,currency,committed_amount_ngn'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'pledge' => $pledge,
            'fulfilled_amount' => $fulfilled,
            'remaining_amount' => $remaining,
            'ledger' => $ledger,
        ];
    }

    private function sumCommittedNgnAggregate(Builder $base): string
    {
        $sum = (clone $base)->selectRaw(
            'SUM(COALESCE(committed_amount_ngn, CASE WHEN UPPER(TRIM(currency)) = ? THEN committed_amount ELSE 0 END)) as aggregate',
            [Currency::NGN->value]
        )->value('aggregate');

        return (string) ($sum ?? '0');
    }

    /**
     * @return list<array{currency: string, total_committed: string, total_committed_ngn: string}>
     */
    private function committedTotalsByCurrencyRows(Builder $base): array
    {
        return (clone $base)
            ->selectRaw(
                'currency, SUM(committed_amount) as total_committed, SUM(COALESCE(committed_amount_ngn, CASE WHEN UPPER(TRIM(currency)) = ? THEN committed_amount ELSE 0 END)) as total_committed_ngn',
                [Currency::NGN->value]
            )
            ->groupBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'total_committed' => (string) $row->total_committed,
                'total_committed_ngn' => (string) $row->total_committed_ngn,
            ])
            ->values()
            ->all();
    }
}
