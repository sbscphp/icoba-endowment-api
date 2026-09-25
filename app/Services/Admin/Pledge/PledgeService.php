<?php

namespace App\Services\Admin\Pledge;

use App\Enums\Currency;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Donation\CampaignAnonymousDonationValidator;
use App\Services\Donation\CampaignContributionValidator;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use App\Services\Pledge\PledgeScheduleSummaryBuilder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PledgeService
{
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly PledgeBalanceService $balanceService,
        private readonly CampaignAnonymousDonationValidator $anonymousDonationValidator,
        private readonly CampaignContributionValidator $campaignContributionValidator,
        private readonly PledgeScheduleService $scheduleService,
        private readonly PledgeScheduleSummaryBuilder $summaryBuilder,
    ) {}

    /**
     * Admin pledge listing: search, date range, sorting and filters, with each
     * row hydrated with balance, donor and schedule summary attributes.
     *
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
        $paginator = $this->listQuery($validated)->paginate($perPage);
        $this->hydrateListAttributes($paginator->items());

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<Pledge>
     */
    private function listQuery(array $validated): Builder
    {
        $query = Pledge::query()->with([
            'campaign:uuid,name,campaign_id,status,allow_anonymous_donation',
            'donor:uuid,firstname,lastname,email,phone_number,organization_name,graduation_set_uuid,donor_type_uuid,house,affiliated_graduation_set_uuid,is_igbobian_owned',
            'donor.graduationSet:uuid,name,set_number',
            'donor.affiliatedGraduationSet:uuid,name,set_number',
            'donor.donorType:uuid,slug,label',
            'donorType:uuid,slug,label',
            'graduationSet:uuid,name,set_number',
            'givingIdentity:uuid,house,affiliated_graduation_set_uuid,is_igbobian_owned',
            'givingIdentity.affiliatedGraduationSet:uuid,name,set_number',
        ]);

        ListingFilterRules::applyResolvedDateRange($query, $validated);
        $this->applyCommonFilters($query, $validated);

        $donorTypeUuid = data_get($validated, 'filters.donor_type_uuid');
        if (is_string($donorTypeUuid) && $donorTypeUuid !== '') {
            $query->where(function (Builder $builder) use ($donorTypeUuid): void {
                $builder->where('donor_type_uuid', $donorTypeUuid)
                    ->orWhereHas('donor', fn (Builder $donor) => $donor->where('donor_type_uuid', $donorTypeUuid));
            });
        }

        $setUuid = data_get($validated, 'filters.graduation_set_uuid');
        if (is_string($setUuid) && $setUuid !== '') {
            $query->where(function (Builder $builder) use ($setUuid): void {
                $builder->where('graduation_set_uuid', $setUuid)
                    ->orWhereHas('donor', fn (Builder $donor) => $donor->where('graduation_set_uuid', $setUuid));
            });
        }

        $hasAccount = data_get($validated, 'filters.has_user_account');
        if ($hasAccount !== null && $hasAccount !== '') {
            in_array($hasAccount, ['1', 1, true, 'true'], true)
                ? $query->whereNotNull('user_uuid')
                : $query->whereNull('user_uuid');
        }

        $minCommitted = data_get($validated, 'filters.min_committed_amount');
        if (is_numeric($minCommitted)) {
            $query->where('committed_amount_ngn', '>=', (float) $minCommitted);
        }

        $maxCommitted = data_get($validated, 'filters.max_committed_amount');
        if (is_numeric($maxCommitted)) {
            $query->where('committed_amount_ngn', '<=', (float) $maxCommitted);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('uuid', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('donor_phone', 'like', $like)
                    ->orWhereHas('donor', function (Builder $donor) use ($like): void {
                        $donor->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone_number', 'like', $like)
                            ->orWhere('organization_name', 'like', $like);
                    })
                    ->orWhereHas('campaign', function (Builder $campaign) use ($like): void {
                        $campaign->where('name', 'like', $like)
                            ->orWhere('campaign_id', 'like', $like);
                    });
            });
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $allowedSorts = ['donor_name', 'committed_amount', 'committed_amount_ngn', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortDirection)->orderBy('id', 'desc');
    }

    /**
     * Attach fulfilled/remaining amounts and a schedule summary to each pledge
     * (used by list rows and the detail view).
     *
     * @param  list<Pledge>  $pledges
     */
    public function hydrateListAttributes(array $pledges): void
    {
        if ($pledges === []) {
            return;
        }

        $collection = new EloquentCollection($pledges);
        $uuids = $collection->pluck('uuid')->all();
        $fulfilledByPledge = $this->balanceService->fulfilledAmountsFor($uuids);
        $lastPayments = $this->lastPaymentDates($uuids);
        $schedules = $this->scheduleService->buildForPledges($collection);

        foreach ($pledges as $pledge) {
            $fulfilled = $fulfilledByPledge[$pledge->uuid] ?? '0';
            $pledge->setAttribute('fulfilled_amount', $fulfilled);
            $pledge->setAttribute('remaining_amount', $this->balanceService->remainingFromFulfilled($pledge, $fulfilled));

            $schedule = $schedules[$pledge->uuid] ?? null;
            $pledge->setAttribute(
                'schedule_summary',
                is_array($schedule)
                    ? $this->summaryBuilder->build($pledge, $schedule, $lastPayments[$pledge->uuid] ?? null)
                    : null,
            );
        }
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
            'campaign:uuid,name,campaign_id,status,allow_anonymous_donation',
            'donor:uuid,firstname,lastname,email,phone_number,house,affiliated_graduation_set_uuid,is_igbobian_owned',
            'donor.affiliatedGraduationSet:uuid,name,set_number',
            'givingIdentity:uuid,house,affiliated_graduation_set_uuid,is_igbobian_owned',
            'givingIdentity.affiliatedGraduationSet:uuid,name,set_number',
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
        $balanceStats = $this->balanceStats($base);

        return array_merge(
            $this->countStats($base),
            [
                'committed_total_ngn' => $this->sumCommittedNgnAggregate($base),
                'committed_totals_by_currency' => $this->committedTotalsByCurrencyRows($base),
                'total_pledge_value' => $this->formatDecimalString((float) $this->sumCommittedNgnAggregate($base)),
                'total_fulfilled' => $balanceStats['fulfilled_total_ngn'],
                'total_fufilled' => $balanceStats['fulfilled_total_ngn'],
                'total_active' => $balanceStats['outstanding_total_ngn'],
                'total_pending' => $balanceStats['outstanding_total_ngn'],
                'total_penidng' => $balanceStats['outstanding_total_ngn'],
            ],
            $balanceStats,
            ['schedule_health' => $this->scheduleHealth($base)],
        );
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

        return array_merge(
            $this->countStats($base),
            [
                'committed_total_ngn' => $this->sumCommittedNgnAggregate($base),
                'committed_totals_by_currency' => $this->committedTotalsByCurrencyRows($base),
            ],
        );
    }

    /**
     * @param  Builder<Pledge>  $base
     * @return array<string, int>
     */
    private function countStats(Builder $base): array
    {
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
        ];
    }

    /**
     * Paid vs outstanding totals (NGN and per currency). Payments counted per pledge
     * are capped at the committed amount so overpayments do not inflate fulfilment.
     *
     * @param  Builder<Pledge>  $base
     * @return array<string, mixed>
     */
    private function balanceStats(Builder $base): array
    {
        $paidSub = Transaction::query()
            ->whereNotNull('pledge_uuid')
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->where(function (Builder $b): void {
                $b->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            })
            ->selectRaw('pledge_uuid, SUM(amount) as paid_amount')
            ->groupBy('pledge_uuid');

        $fulfilled = 'CASE WHEN COALESCE(pt.paid_amount, 0) > pledges.committed_amount THEN pledges.committed_amount ELSE COALESCE(pt.paid_amount, 0) END';
        $rate = 'COALESCE(NULLIF(pledges.exchange_rate_to_naira, 0), CASE WHEN pledges.committed_amount_ngn IS NOT NULL AND pledges.committed_amount > 0 THEN pledges.committed_amount_ngn / pledges.committed_amount WHEN UPPER(TRIM(pledges.currency)) = ? THEN 1 ELSE 0 END)';

        $rows = (clone $base)
            ->leftJoinSub($paidSub, 'pt', 'pt.pledge_uuid', '=', 'pledges.uuid')
            ->selectRaw(
                'pledges.currency, '.
                'SUM(pledges.committed_amount) as committed, '.
                "SUM({$fulfilled}) as fulfilled, ".
                "SUM(pledges.committed_amount - ({$fulfilled})) as remaining, ".
                "SUM(({$fulfilled}) * ({$rate})) as fulfilled_ngn, ".
                "SUM((pledges.committed_amount - ({$fulfilled})) * ({$rate})) as remaining_ngn",
                [Currency::NGN->value, Currency::NGN->value]
            )
            ->groupBy('pledges.currency')
            ->get();

        $fulfilledNgn = 0.0;
        $remainingNgn = 0.0;
        $byCurrency = [];
        foreach ($rows as $row) {
            $fulfilledNgn += (float) $row->fulfilled_ngn;
            $remainingNgn += (float) $row->remaining_ngn;
            $byCurrency[] = [
                'currency' => (string) $row->currency,
                'total_committed' => $this->money((float) $row->committed),
                'total_fulfilled' => $this->money((float) $row->fulfilled),
                'total_remaining' => $this->money((float) $row->remaining),
                'total_fulfilled_ngn' => $this->money((float) $row->fulfilled_ngn),
                'total_remaining_ngn' => $this->money((float) $row->remaining_ngn),
            ];
        }

        $committedNgn = $fulfilledNgn + $remainingNgn;

        return [
            'fulfilled_total_ngn' => $this->money($fulfilledNgn),
            'outstanding_total_ngn' => $this->money($remainingNgn),
            'fulfillment_rate_percent' => $committedNgn > self::EPSILON
                ? round($fulfilledNgn / $committedNgn * 100, 2)
                : 0.0,
            'balance_totals_by_currency' => $byCurrency,
        ];
    }

    /**
     * Overdue / paused / upcoming figures derived from installment schedules of
     * the active pledges in the filtered set.
     *
     * @param  Builder<Pledge>  $base
     * @return array<string, mixed>
     */
    private function scheduleHealth(Builder $base): array
    {
        $today = now((string) config('app.timezone', 'UTC'))->startOfDay();
        $upcomingEnd = $today->copy()->addDays(7)->toDateString();

        $out = [
            'overdue_pledge_count' => 0,
            'overdue_installment_count' => 0,
            'overdue_amount_ngn' => 0.0,
            'paused_pledge_count' => 0,
            'due_within_7_days_pledge_count' => 0,
            'due_within_7_days_amount_ngn' => 0.0,
        ];

        (clone $base)
            ->where('status', PledgeStatus::ACTIVE)
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $pledges) use (&$out, $today, $upcomingEnd): void {
                $schedules = $this->scheduleService->buildForPledges($pledges);

                foreach ($pledges as $pledge) {
                    $schedule = $schedules[$pledge->uuid] ?? null;
                    if (! is_array($schedule)) {
                        continue;
                    }

                    if ($this->scheduleService->isPledgePaused($pledge)) {
                        $out['paused_pledge_count']++;

                        continue;
                    }

                    $summary = $this->summaryBuilder->build($pledge, $schedule, null, $today);
                    if ($summary['is_overdue']) {
                        $out['overdue_pledge_count']++;
                        $out['overdue_installment_count'] += (int) $summary['overdue_installments'];
                        $out['overdue_amount_ngn'] += (float) $summary['overdue_amount_ngn'];
                    }

                    $next = $summary['next_installment'];
                    $due = is_array($next) ? ($next['due_date'] ?? null) : null;
                    if (is_string($due) && $due > $today->toDateString() && $due <= $upcomingEnd) {
                        $out['due_within_7_days_pledge_count']++;
                        $out['due_within_7_days_amount_ngn'] += (float) ($next['remaining_amount_ngn'] ?? 0);
                    }
                }
            });

        $out['overdue_amount_ngn'] = $this->money($out['overdue_amount_ngn']);
        $out['due_within_7_days_amount_ngn'] = $this->money($out['due_within_7_days_amount_ngn']);

        return $out;
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
            $query->where('pledges.created_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('pledges.created_at', '<=', $end);
        }

        $this->applyCommonFilters($query, $validated);

        return $query;
    }

    /**
     * Filters shared by the listing and stats queries.
     *
     * @param  Builder<Pledge>  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyCommonFilters(Builder $query, array $validated): void
    {
        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('pledges.status', $status);
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('pledges.campaign_uuid', $campaignUuid);
        }

        $userUuid = data_get($validated, 'filters.user_uuid');
        if (is_string($userUuid) && $userUuid !== '') {
            $query->where('pledges.user_uuid', $userUuid);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('pledges.currency', strtoupper($currency));
        }

        $plan = data_get($validated, 'filters.payment_plan_type');
        if (is_string($plan) && $plan !== '') {
            $query->where('pledges.payment_plan_type', $plan);
        }

        $anonymous = data_get($validated, 'filters.is_anonymous');
        if ($anonymous !== null && $anonymous !== '') {
            $truthy = in_array($anonymous, ['1', 1, true, 'true'], true);
            $query->where('pledges.is_anonymous', $truthy);
        }

        $isTest = data_get($validated, 'filters.is_test');
        if ($isTest !== null && $isTest !== '') {
            $query->where('pledges.is_test', in_array($isTest, ['1', 1, true, 'true'], true));
        }

        $affiliatedSetUuid = data_get($validated, 'filters.affiliated_graduation_set_uuid');
        if (is_string($affiliatedSetUuid) && $affiliatedSetUuid !== '') {
            $query->where(function (Builder $builder) use ($affiliatedSetUuid): void {
                $builder->whereHas('donor', fn (Builder $donor) => $donor->where('affiliated_graduation_set_uuid', $affiliatedSetUuid))
                    ->orWhereHas('givingIdentity', fn (Builder $identity) => $identity->where('affiliated_graduation_set_uuid', $affiliatedSetUuid));
            });
        }

        $house = data_get($validated, 'filters.house');
        if (is_string($house) && $house !== '') {
            $house = strtolower($house);
            $query->where(function (Builder $builder) use ($house): void {
                $builder->whereHas('donor', fn (Builder $donor) => $donor->where('house', $house))
                    ->orWhereHas('givingIdentity', fn (Builder $identity) => $identity->where('house', $house));
            });
        }
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

        $detail = $this->detailWithLedger($pledgeUuid, $perPage);
        // Admin reminder history (who sent it, internal notes) is not for the donor.
        unset($detail['reminders']);

        return $detail;
    }

    public function findByUuid(string $uuid): Pledge
    {
        $pledge = Pledge::query()
            ->where('uuid', $uuid)
            ->with([
                'campaign:uuid,name,campaign_id,status,allow_anonymous_donation',
                'donor:uuid,firstname,lastname,email,phone_number,organization_name,graduation_set_uuid,donor_type_uuid,house,affiliated_graduation_set_uuid,is_igbobian_owned',
                'donor.graduationSet:uuid,name,set_number',
                'donor.affiliatedGraduationSet:uuid,name,set_number',
                'donor.donorType:uuid,slug,label',
                'donorType:uuid,slug,label',
                'graduationSet:uuid,name,set_number',
                'givingIdentity:uuid,house,affiliated_graduation_set_uuid,is_igbobian_owned',
                'givingIdentity.affiliatedGraduationSet:uuid,name,set_number',
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
        $campaignUuid = (string) ($data['campaign_uuid'] ?? '');
        $this->campaignContributionValidator->assertAcceptingContributions($campaignUuid);
        $this->anonymousDonationValidator->assertAllowed(
            $campaignUuid,
            (bool) ($data['is_anonymous'] ?? false),
        );

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
            'purpose' => $pledge->purpose,
            'amount' => $amount,
            'currency' => $pledge->currency,
            'amount_in_naira' => null,
            'status' => TransactionStatus::PENDING,
            'gateway' => $gateway,
            'application_type' => TransactionApplicationType::PLEDGE_PLACEHOLDER,
        ]);
    }

    /**
     * Pledge detail: balances, full schedule view, schedule summary, payment
     * summary, reminder history and a paginated transaction ledger.
     *
     * @return array{pledge: Pledge, fulfilled_amount: string, remaining_amount: string, schedule: array<string, mixed>, summary: array<string, mixed>, payment_summary: array<string, mixed>, reminders: array<string, mixed>, ledger: LengthAwarePaginator}
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

        $schedule = $this->scheduleService->buildForPledge($pledge);
        $lastPayments = $this->lastPaymentDates([$pledge->uuid]);
        $summary = $this->summaryBuilder->build($pledge, $schedule, $lastPayments[$pledge->uuid] ?? null);

        $summary['number_of_transactions'] = (int) Transaction::query()
            ->where('pledge_uuid', $pledge->uuid)
            ->whereNotIn('status', [TransactionStatus::SUPERSEDED->value])
            ->count();

        return [
            'pledge' => $pledge,
            'fulfilled_amount' => $fulfilled,
            'remaining_amount' => $remaining,
            'schedule' => $schedule,
            'summary' => $summary,
            'payment_summary' => $this->paymentSummary($pledge),
            'reminders' => [
                'automatic' => $this->automaticReminderRows($pledge),
                'manual' => array_reverse($this->summaryBuilder->manualReminders($pledge)),
                'last_reminder_sent_at' => $summary['last_reminder_sent_at'],
            ],
            'ledger' => $ledger,
        ];
    }

    /**
     * Counts and totals of linked transactions grouped by status (placeholders excluded).
     *
     * @return array<string, mixed>
     */
    private function paymentSummary(Pledge $pledge): array
    {
        $rows = Transaction::query()
            ->where('pledge_uuid', $pledge->uuid)
            ->where(function (Builder $b): void {
                $b->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            })
            ->whereNotIn('status', [TransactionStatus::SUPERSEDED->value])
            ->selectRaw('status, COUNT(*) as total_count, SUM(amount) as total_amount, SUM(COALESCE(amount_in_naira, 0)) as total_amount_ngn, MAX(COALESCE(paid_at, created_at)) as last_at')
            ->groupBy('status')
            ->get();

        $byStatus = [];
        $successful = ['count' => 0, 'amount' => '0.00', 'amount_ngn' => '0.00', 'last_paid_at' => null];
        $pending = ['count' => 0, 'amount' => '0.00', 'amount_ngn' => '0.00'];

        foreach ($rows as $row) {
            $status = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;
            $entry = [
                'count' => (int) $row->total_count,
                'amount' => $this->money((float) $row->total_amount),
                'amount_ngn' => $this->money((float) $row->total_amount_ngn),
                'last_at' => $row->last_at !== null ? Carbon::parse((string) $row->last_at)->toIso8601String() : null,
            ];
            $byStatus[$status] = $entry;

            if ($status === TransactionStatus::SUCCESSFUL->value) {
                $successful = [
                    'count' => $entry['count'],
                    'amount' => $entry['amount'],
                    'amount_ngn' => $entry['amount_ngn'],
                    'last_paid_at' => $entry['last_at'],
                ];
            } elseif ($status === TransactionStatus::PENDING->value) {
                $pending = [
                    'count' => $entry['count'],
                    'amount' => $entry['amount'],
                    'amount_ngn' => $entry['amount_ngn'],
                ];
            }
        }

        return [
            'currency' => $pledge->currency,
            'successful' => $successful,
            'pending' => $pending,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Automatic (scheduled) reminder keys stored as "<schedule_item_id>:<due_date>".
     *
     * @return list<array{schedule_item_id: string, due_date: string|null}>
     */
    private function automaticReminderRows(Pledge $pledge): array
    {
        $sent = data_get($pledge->metadata, 'payment_reminders_sent', []);
        if (! is_array($sent)) {
            return [];
        }

        $rows = [];
        foreach ($sent as $key) {
            if (! is_string($key)) {
                continue;
            }
            $pos = strrpos($key, ':');
            $rows[] = [
                'schedule_item_id' => $pos !== false ? substr($key, 0, $pos) : $key,
                'due_date' => $pos !== false ? substr($key, $pos + 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $pledgeUuids
     * @return array<string, string> ISO-8601 timestamp of the latest counted payment, keyed by pledge uuid
     */
    private function lastPaymentDates(array $pledgeUuids): array
    {
        if ($pledgeUuids === []) {
            return [];
        }

        $rows = Transaction::query()
            ->whereIn('pledge_uuid', $pledgeUuids)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->where(function (Builder $b): void {
                $b->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            })
            ->selectRaw('pledge_uuid, MAX(COALESCE(paid_at, created_at)) as last_paid_at')
            ->groupBy('pledge_uuid')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row->last_paid_at !== null) {
                $out[(string) $row->pledge_uuid] = Carbon::parse((string) $row->last_paid_at)->toIso8601String();
            }
        }

        return $out;
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

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatDecimalString(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
