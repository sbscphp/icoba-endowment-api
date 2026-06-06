<?php

namespace App\Services\Public;

use App\Enums\CampaignStatus;
use App\Enums\Currency;
use App\Enums\DonorTypeSlug;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\DonorType;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Tier\TierResolutionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardService
{
    public function __construct(
        private readonly TierResolutionService $tierResolution,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function donorsLeaderboard(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $mode = (string) ($filters['mode'] ?? 'all');
        $campaignUuid = isset($filters['campaign_uuid']) ? (string) $filters['campaign_uuid'] : null;
        $donorTypeUuid = isset($filters['donor_type_uuid']) ? (string) $filters['donor_type_uuid'] : null;
        $setUuid = isset($filters['graduation_set_uuid']) ? (string) $filters['graduation_set_uuid'] : null;
        $tierUuid = isset($filters['tier_uuid']) ? (string) $filters['tier_uuid'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $scope = strtolower(trim((string) ($filters['scope'] ?? 'all')));
        $displayCurrency = $this->resolveDisplayCurrency($filters);
        $amountColumn = $this->resolveAmountColumn($filters);
        $effectiveNgnSql = $this->effectiveAmountNgnSql('transactions');

        $keySql = <<<'SQL'
CASE
  WHEN transactions.giving_identity_uuid IS NOT NULL THEN transactions.giving_identity_uuid
  WHEN transactions.user_uuid IS NOT NULL THEN transactions.user_uuid
  WHEN transactions.donor_email IS NOT NULL AND transactions.donor_email != '' THEN LOWER(TRIM(transactions.donor_email))
  ELSE transactions.uuid
END
SQL;

        $base = Transaction::query()->countableTowardRevenue();

        if ($campaignUuid !== null && $campaignUuid !== '') {
            $base->where('transactions.campaign_uuid', $campaignUuid);
        }

        if ($scope === 'donations') {
            $base->whereNull('transactions.pledge_uuid');
        } elseif ($scope === 'pledges') {
            $base->whereNotNull('transactions.pledge_uuid');
        }

        if ($mode === 'donor_type' && $donorTypeUuid !== null && $donorTypeUuid !== '') {
            $base->where(function (Builder $q) use ($donorTypeUuid): void {
                $q->where('transactions.donor_type_uuid', $donorTypeUuid)
                    ->orWhereHas('donor', fn(Builder $b) => $b->where('donor_type_uuid', $donorTypeUuid));
            });
        }

        if ($mode === 'set' && $setUuid !== null && $setUuid !== '') {
            $base->whereHas('donor', fn(Builder $b) => $b->where('graduation_set_uuid', $setUuid));
        }

        $inner = $base->clone()
            ->leftJoin('users', 'users.uuid', '=', 'transactions.user_uuid')
            ->leftJoin('giving_identities', 'giving_identities.uuid', '=', 'transactions.giving_identity_uuid')
            ->leftJoin('sets as identity_sets', 'identity_sets.uuid', '=', 'giving_identities.graduation_set_uuid')
            ->leftJoin('corporate_categories as identity_categories', 'identity_categories.uuid', '=', 'giving_identities.corporate_category_uuid')
            ->select([
                'transactions.user_uuid',
                'transactions.giving_identity_uuid',
                'transactions.donor_name',
                'transactions.donor_email',
                'transactions.is_anonymous',
                'transactions.amount',
                'transactions.currency',
                'transactions.amount_in_naira',
                'transactions.paid_at',
                'transactions.donor_type_uuid',
                'transactions.organization_name',
                'transactions.metadata',
            ])
            ->selectRaw('(' . $keySql . ') as donor_key')
            ->selectRaw('(' . $effectiveNgnSql . ') as effective_amount_ngn')
            ->selectRaw("COALESCE(NULLIF(TRIM(transactions.donor_name), ''), NULLIF(TRIM(CONCAT(COALESCE(users.firstname, ''), ' ', COALESCE(users.lastname, ''))), ''), NULLIF(TRIM(transactions.donor_email), ''), 'Donor') as sort_name")
            ->selectRaw('giving_identities.donor_type_uuid as identity_donor_type_uuid')
            ->selectRaw('giving_identities.organization_name as identity_organization_name')
            ->selectRaw('identity_sets.set_number as identity_set_number')
            ->selectRaw('identity_categories.name as identity_corporate_category_name');

        $totalAmountSql = $amountColumn === 'amount_in_naira'
            ? 'SUM(effective_amount_ngn)'
            : 'SUM(amount)';

        $sort = $this->resolveSort($filters, 'amount', 'desc');

        $query = DB::query()
            ->fromSub($inner, 'donor_transactions')
            ->select('donor_key')
            ->selectRaw('MAX(user_uuid) as user_uuid')
            ->selectRaw('MAX(donor_name) as donor_name')
            ->selectRaw('MAX(donor_email) as donor_email')
            ->selectRaw('MIN(is_anonymous) as all_anonymous')
            ->selectRaw($totalAmountSql . ' as total_amount')
            ->selectRaw('SUM(effective_amount_ngn) as total_amount_ngn')
            ->selectRaw('MAX(paid_at) as last_paid_at')
            ->selectRaw('MAX(donor_type_uuid) as donor_type_uuid')
            ->selectRaw('MAX(identity_donor_type_uuid) as identity_donor_type_uuid')
            ->selectRaw('MAX(organization_name) as organization_name')
            ->selectRaw('MAX(identity_organization_name) as identity_organization_name')
            ->selectRaw('MAX(identity_set_number) as identity_set_number')
            ->selectRaw('MAX(identity_corporate_category_name) as identity_corporate_category_name')
            ->selectRaw("MAX(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.guest_donor_profile.set_number'))) as guest_set_number")
            ->selectRaw("MAX(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.guest_donor_profile.corporate_category_name'))) as guest_corporate_category_name")
            ->selectRaw("CASE WHEN MIN(is_anonymous) = 1 THEN 'Anonymous' ELSE MAX(sort_name) END as display_sort_name")
            ->groupBy('donor_key');

        $this->applyTierFilter($query, $tierUuid);
        $this->applyDonorSearchFilter($query, $search);

        if ($sort['by'] === 'name') {
            $query->orderBy('display_sort_name', $sort['dir']);
        } else {
            $query->orderBy('total_amount', $sort['dir']);
        }

        $paginator = $query->paginate($perPage);

        $userUuids = $paginator->getCollection()->pluck('user_uuid')->filter()->unique()->all();
        $users = User::query()
            ->with([
                'graduationSet:uuid,name,set_number',
                'donorType:uuid,label,slug',
                'corporateCategory:uuid,name',
            ])
            ->whereIn('uuid', $userUuids)
            ->get()
            ->keyBy('uuid');

        $guestDonorTypeUuids = $paginator->getCollection()
            ->flatMap(fn (object $row): array => array_filter([
                $row->donor_type_uuid ?? null,
                $row->identity_donor_type_uuid ?? null,
            ]))
            ->unique()
            ->all();

        $guestDonorTypes = DonorType::query()
            ->whereIn('uuid', $guestDonorTypeUuids)
            ->get(['uuid', 'slug', 'label'])
            ->keyBy('uuid');

        $donorTypesBySlug = DonorType::query()
            ->get(['uuid', 'slug', 'label'])
            ->keyBy('slug');

        $rank = ($paginator->currentPage() - 1) * $paginator->perPage();
        $paginator->getCollection()->transform(function ($row) use ($users, $guestDonorTypes, $donorTypesBySlug, &$rank, $displayCurrency) {
            /** @var object $row */
            $rank++;

            return $this->mapAggregatedDonorRow($row, $users, $guestDonorTypes, $donorTypesBySlug, $rank, $displayCurrency);
        });

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function setsLeaderboard(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $scope = strtolower(trim((string) ($filters['scope'] ?? 'all')));
        $displayCurrency = $this->resolveDisplayCurrency($filters);
        $sort = $this->resolveSort($filters, 'amount', 'desc', ['amount', 'set']);
        $query = $this->buildSetTotalsQuery($filters, $scope);

        if ($sort['by'] === 'set') {
            $query->orderBy('sets.set_number', $sort['dir'])->orderBy('sets.name', $sort['dir']);
        } else {
            $query->orderBy('set_totals.total_amount', $sort['dir']);
        }

        $paginator = $query->paginate($perPage);
        $rank = ($paginator->currentPage() - 1) * $paginator->perPage();
        $paginator->setCollection(collect($this->mapSetLeaderboardRows($paginator->getCollection(), $displayCurrency, $rank)));

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{donations: list<array<string, mixed>>, pledges: list<array<string, mixed>>}
     */
    public function topSets(array $filters): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 3), 10));
        $displayCurrency = $this->resolveDisplayCurrency($filters);

        return [
            'donations' => $this->topSetsForScope($filters, 'donations', $limit, $displayCurrency),
            'pledges' => $this->topSetsForScope($filters, 'pledges', $limit, $displayCurrency),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function recentDonations(string $campaignUuid, int $limit = 20): Collection
    {
        $q = Transaction::query()->countableTowardRevenue()
            ->with(['donor:uuid,firstname,lastname,graduation_set_uuid,donor_type_uuid', 'donor.graduationSet:uuid,name,set_number', 'donor.donorType:uuid,label,slug'])
            ->orderByDesc('paid_at')
            ->limit($limit);

        if ($campaignUuid !== '') {
            $q->where('campaign_uuid', $campaignUuid);
        }

        return $q->get()->map(fn(Transaction $tx) => $this->mapTransactionForPublic($tx));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function campaignsFundProgressList(array $filters): array
    {
        $displayCurrency = $this->resolveDisplayCurrency($filters);
        $amountColumn = $this->resolveAmountColumn($filters);

        $raisedSubquery = DB::table('transactions')
            ->select('campaign_uuid')
            ->selectRaw("COALESCE(SUM({$amountColumn}), 0) as raised")
            ->where('status', TransactionStatus::SUCCESSFUL->value)
            ->where(function ($query): void {
                $query->whereNull('application_type')
                    ->orWhere('application_type', '!=', TransactionApplicationType::PLEDGE_PLACEHOLDER->value);
            })
            ->whereNull('deleted_at')
            ->groupBy('campaign_uuid');

        return DB::table('campaigns')
            ->leftJoinSub($raisedSubquery, 'raised_totals', 'raised_totals.campaign_uuid', '=', 'campaigns.uuid')
            ->where('campaigns.allow_public_donation', true)
            ->where('campaigns.status', '!=', CampaignStatus::DRAFT->value)
            ->whereNull('campaigns.deleted_at')
            ->orderBy('campaigns.name')
            ->select([
                'campaigns.uuid as campaign_uuid',
                'campaigns.name as campaign_name',
                'campaigns.target_amount',
                DB::raw('COALESCE(raised_totals.raised, 0) as raised'),
            ])
            ->get()
            ->map(function (object $row) use ($displayCurrency): array {
                $raised = (float) $row->raised;
                $target = (float) $row->target_amount;
                $percent = $target > 0 ? round(min(100, ($raised / $target) * 100), 2) : 0.0;

                return [
                    'campaign_uuid' => $row->campaign_uuid,
                    'campaign_name' => $row->campaign_name,
                    'currency' => $displayCurrency,
                    'raised' => (string) $raised,
                    'target' => (string) $target,
                    'percent' => $percent,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function campaignFundProgress(string $campaignUuid, array $filters): array
    {
        $campaign = Campaign::query()->where('uuid', $campaignUuid)->firstOrFail();
        $amountColumn = $this->resolveAmountColumn($filters);

        $raised = (float) Transaction::query()
            ->countableTowardRevenue()
            ->where('campaign_uuid', $campaignUuid)
            ->sum($amountColumn);

        $target = (float) $campaign->target_amount;
        $percent = $target > 0 ? round(min(100, ($raised / $target) * 100), 2) : 0.0;

        return [
            'campaign_uuid' => $campaign->uuid,
            'campaign_name' => $campaign->name,
            'currency' => $this->resolveDisplayCurrency($filters),
            'raised' => (string) $raised,
            'target' => (string) $target,
            'percent' => $percent,
        ];
    }

    /**
     * @param  Collection<string, User>  $users
     * @param  Collection<string, DonorType>  $guestDonorTypes
     * @param  Collection<string, DonorType>  $donorTypesBySlug
     * @return array<string, mixed>
     */
    private function mapAggregatedDonorRow(
        object $row,
        Collection $users,
        Collection $guestDonorTypes,
        Collection $donorTypesBySlug,
        int $rank,
        string $displayCurrency,
    ): array {
        $totalNgn = (float) ($row->total_amount_ngn ?? 0);
        $allAnonymous = (int) ($row->all_anonymous ?? 0) === 1;

        $payload = [
            'rank' => $rank,
            'total_amount' => $this->formatLeaderboardAmount($row->total_amount ?? null),
            'amount_in_ngn' => $this->formatLeaderboardAmount($row->total_amount_ngn ?? null),
            'currency' => $displayCurrency,
            'tier' => $this->tierResolution->resolvePublicTierForCumulativeAmount($totalNgn),
            'last_donation_at' => $row->last_paid_at,
        ];

        if ($allAnonymous) {
            $payload['display_name'] = 'Anonymous';

            return $payload;
        }

        $user = ! empty($row->user_uuid) ? ($users[(string) $row->user_uuid] ?? null) : null;

        if ($user !== null) {
            $payload['display_name'] = trim(implode(' ', array_filter([(string) $user->firstname, (string) $user->lastname])));
        } else {
            $payload['display_name'] = (string) ($row->donor_name ?: 'Donor');
        }

        $donorMeta = $this->resolveLeaderboardDonorMeta($user, $row, $guestDonorTypes, $donorTypesBySlug);
        $payload['donor_type'] = $donorMeta['donor_type'];
        $payload['info'] = $donorMeta['info'];

        return $payload;
    }

    /**
     * @param  Collection<string, DonorType>  $guestDonorTypes
     * @param  Collection<string, DonorType>  $donorTypesBySlug
     * @return array{donor_type: ?array{slug: string, label: string}, info: ?string}
     */
    private function resolveLeaderboardDonorMeta(
        ?User $user,
        object $row,
        Collection $guestDonorTypes,
        Collection $donorTypesBySlug,
    ): array {
        $donorType = $this->resolveLeaderboardDonorType($user, $row, $guestDonorTypes, $donorTypesBySlug);

        if ($donorType === null) {
            return [
                'donor_type' => null,
                'info' => null,
            ];
        }

        $slug = (string) $donorType->slug;
        $info = null;

        if ($slug === DonorTypeSlug::ICOBA_ALUMNI->value) {
            $setNumber = $user?->graduationSet?->set_number;
            if ($setNumber === null || (string) $setNumber === '') {
                $setNumber = is_string($row->identity_set_number ?? null) && trim((string) $row->identity_set_number) !== ''
                    ? trim((string) $row->identity_set_number)
                    : null;
            }
            if ($setNumber === null || (string) $setNumber === '') {
                $setNumber = is_string($row->guest_set_number ?? null) && trim((string) $row->guest_set_number) !== ''
                    ? trim((string) $row->guest_set_number)
                    : null;
            }
            $info = $setNumber !== null && (string) $setNumber !== '' ? (string) $setNumber : null;
        } elseif ($slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            $categoryName = $user?->corporateCategory?->name;
            if (is_string($categoryName) && trim($categoryName) !== '') {
                $info = trim($categoryName);
            } elseif (is_string($row->identity_corporate_category_name ?? null) && trim((string) $row->identity_corporate_category_name) !== '') {
                $info = trim((string) $row->identity_corporate_category_name);
            } elseif (is_string($row->guest_corporate_category_name ?? null) && trim((string) $row->guest_corporate_category_name) !== '') {
                $info = trim((string) $row->guest_corporate_category_name);
            } elseif (is_string($user?->organization_name) && trim($user->organization_name) !== '') {
                $info = trim($user->organization_name);
            } elseif (is_string($row->identity_organization_name ?? null) && trim((string) $row->identity_organization_name) !== '') {
                $info = trim((string) $row->identity_organization_name);
            } elseif (is_string($row->organization_name ?? null) && trim((string) $row->organization_name) !== '') {
                $info = trim((string) $row->organization_name);
            }
        }

        return [
            'donor_type' => [
                'slug' => $slug,
                'label' => (string) $donorType->label,
            ],
            'info' => $info,
        ];
    }

    /**
     * @param  Collection<string, DonorType>  $guestDonorTypes
     * @param  Collection<string, DonorType>  $donorTypesBySlug
     */
    private function resolveLeaderboardDonorType(
        ?User $user,
        object $row,
        Collection $guestDonorTypes,
        Collection $donorTypesBySlug,
    ): ?DonorType {
        if ($user?->donorType !== null) {
            return $user->donorType;
        }

        $donorTypeUuid = $row->identity_donor_type_uuid ?? $row->donor_type_uuid ?? null;
        if (is_string($donorTypeUuid) && $donorTypeUuid !== '' && $guestDonorTypes->has($donorTypeUuid)) {
            return $guestDonorTypes->get($donorTypeUuid);
        }

        $organizationName = is_string($user?->organization_name)
            ? trim($user->organization_name)
            : (is_string($row->identity_organization_name ?? null)
                ? trim((string) $row->identity_organization_name)
                : (is_string($row->organization_name ?? null) ? trim((string) $row->organization_name) : ''));

        if ($organizationName !== '') {
            return $this->donorTypeFromSlug($donorTypesBySlug, DonorTypeSlug::CORPORATE_DONOR);
        }

        if ($user !== null && ($user->graduation_set_uuid !== null || filled($user->alumni_identifier))) {
            return $this->donorTypeFromSlug($donorTypesBySlug, DonorTypeSlug::ICOBA_ALUMNI);
        }

        return null;
    }

    /**
     * @param  Collection<string, DonorType>  $donorTypesBySlug
     */
    private function donorTypeFromSlug(Collection $donorTypesBySlug, DonorTypeSlug $slug): DonorType
    {
        $existing = $donorTypesBySlug->get($slug->value);

        if ($existing !== null) {
            return $existing;
        }

        $model = new DonorType;
        $model->forceFill([
            'slug' => $slug->value,
            'label' => $slug->label(),
        ]);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTransactionForPublic(Transaction $tx): array
    {
        $row = [
            'transaction_uuid' => $tx->uuid,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'paid_at' => $tx->paid_at,
            'tier' => $this->tierResolution->resolvePublicTierForAmount(
                $tx->amount_in_naira !== null ? (float) $tx->amount_in_naira : null
            ),
        ];

        if ($tx->is_anonymous) {
            $row['display_name'] = 'Anonymous';

            return $row;
        }

        if ($tx->donor !== null) {
            $row['display_name'] = trim(implode(' ', array_filter([(string) $tx->donor->firstname, (string) $tx->donor->lastname])));
            $row['donor_type_label'] = $tx->donor->donorType?->label;
            $row['set_label'] = $tx->donor->graduationSet?->name;
        } else {
            $row['display_name'] = (string) ($tx->donor_name ?? 'Donor');
        }

        return $row;
    }

    private function resolveAmountColumn(array $filters): string
    {
        return $this->resolveDisplayCurrency($filters) === Currency::NGN->value
            ? 'amount_in_naira'
            : 'amount';
    }

    private function resolveDisplayCurrency(array $filters): string
    {
        $currency = strtoupper((string) ($filters['currency'] ?? Currency::NGN->value));

        return in_array($currency, Currency::values(), true) ? $currency : Currency::NGN->value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  'all'|'donations'|'pledges'  $scope
     */
    private function buildSetTotalsQuery(array $filters, string $scope): \Illuminate\Database\Query\Builder
    {
        $campaignUuid = isset($filters['campaign_uuid']) ? (string) $filters['campaign_uuid'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $amountColumn = $this->resolveAmountColumn($filters);
        $effectiveNgnSql = $this->effectiveAmountNgnSql('transactions');
        $setUuidSql = $this->resolvedGraduationSetUuidSql();

        $base = Transaction::query()->countableTowardRevenue()
            ->leftJoin('users', 'users.uuid', '=', 'transactions.user_uuid')
            ->leftJoin('giving_identities', 'giving_identities.uuid', '=', 'transactions.giving_identity_uuid')
            ->whereRaw('(' . $setUuidSql . ') IS NOT NULL');

        if ($campaignUuid !== null && $campaignUuid !== '') {
            $base->where('transactions.campaign_uuid', $campaignUuid);
        }

        if ($scope === 'donations') {
            $base->whereNull('transactions.pledge_uuid');
        } elseif ($scope === 'pledges') {
            $base->whereNotNull('transactions.pledge_uuid');
        }

        if ($search !== '') {
            $like = '%' . $this->escapeLike($search) . '%';
            $base->whereExists(function ($q) use ($like, $setUuidSql): void {
                $q->selectRaw('1')
                    ->from('sets')
                    ->whereRaw('sets.uuid = (' . $setUuidSql . ')')
                    ->where(function ($b) use ($like): void {
                        $b->where('sets.name', 'like', $like)
                            ->orWhere('sets.set_number', 'like', $like);
                    });
            });
        }

        $inner = $base->clone()
            ->select([
                'transactions.amount',
                'transactions.currency',
                'transactions.amount_in_naira',
            ])
            ->selectRaw('(' . $setUuidSql . ') as graduation_set_uuid')
            ->selectRaw('(' . $effectiveNgnSql . ') as effective_amount_ngn');

        $totalAmountSql = $amountColumn === 'amount_in_naira'
            ? 'SUM(effective_amount_ngn)'
            : 'SUM(amount)';

        $aggregated = DB::query()
            ->fromSub($inner, 'set_transactions')
            ->selectRaw('graduation_set_uuid as set_uuid')
            ->selectRaw($totalAmountSql . ' as total_amount')
            ->selectRaw('SUM(effective_amount_ngn) as total_amount_ngn')
            ->groupBy('graduation_set_uuid');

        return DB::query()
            ->fromSub($aggregated, 'set_totals')
            ->join('sets', 'sets.uuid', '=', 'set_totals.set_uuid')
            ->select([
                'set_totals.set_uuid',
                'set_totals.total_amount',
                'set_totals.total_amount_ngn',
                'sets.name as set_name',
                'sets.set_number',
            ])
            ->selectRaw('(SELECT COUNT(*) FROM users WHERE users.graduation_set_uuid = sets.uuid) as total_members');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function topSetsForScope(array $filters, string $scope, int $limit, string $displayCurrency): array
    {
        $rows = $this->buildSetTotalsQuery($filters, $scope)
            ->orderByDesc('set_totals.total_amount')
            ->orderBy('sets.set_number')
            ->limit($limit)
            ->get();

        return $this->mapSetLeaderboardRows($rows, $displayCurrency);
    }

    /**
     * @param  Collection<int, object>|iterable<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapSetLeaderboardRows(iterable $rows, string $displayCurrency, int $rankStart = 0): array
    {
        $rank = $rankStart;
        $mapped = [];

        foreach ($rows as $row) {
            /** @var object $row */
            $rank++;
            $mapped[] = [
                'rank' => $rank,
                'set' => [
                    'graduation_set_uuid' => (string) $row->set_uuid,
                    'set_name' => $row->set_name,
                    'set_number' => $row->set_number,
                    'total_members' => (int) ($row->total_members ?? 0),
                ],
                'total_amount' => $this->formatLeaderboardAmount($row->total_amount ?? null),
                'amount_in_ngn' => $this->formatLeaderboardAmount($row->total_amount_ngn ?? null),
                'currency' => $displayCurrency,
            ];
        }

        return $mapped;
    }

    private function effectiveAmountNgnSql(string $tablePrefix): string
    {
        $prefix = rtrim($tablePrefix, '.') . '.';

        return 'COALESCE(' . $prefix . 'amount_in_naira, CASE WHEN UPPER(TRIM(' . $prefix . 'currency)) = \'NGN\' THEN ' . $prefix . 'amount END)';
    }

    private function resolvedGraduationSetUuidSql(): string
    {
        return <<<'SQL'
COALESCE(
  giving_identities.graduation_set_uuid,
  users.graduation_set_uuid,
  NULLIF(JSON_UNQUOTE(JSON_EXTRACT(transactions.metadata, '$.guest_donor_profile.graduation_set_uuid')), '')
)
SQL;
    }

    private function formatLeaderboardAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  list<string>  $allowed
     * @return array{by: string, dir: string}
     */
    private function resolveSort(array $filters, string $defaultBy, string $defaultDir, array $allowed = ['name', 'amount']): array
    {
        $by = strtolower(trim((string) ($filters['sort_by'] ?? $defaultBy)));
        $dir = strtolower(trim((string) ($filters['sort_dir'] ?? $defaultDir)));

        if (! in_array($by, $allowed, true)) {
            $by = $defaultBy;
        }

        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = $defaultDir;
        }

        return ['by' => $by, 'dir' => $dir];
    }

    private function applyTierFilter(\Illuminate\Database\Query\Builder $query, ?string $tierUuid): void
    {
        if ($tierUuid === null || $tierUuid === '') {
            return;
        }

        $tier = TierConfiguration::query()
            ->where('uuid', $tierUuid)
            ->where('is_active', true)
            ->first(['min_amount', 'max_amount']);

        if ($tier === null) {
            return;
        }

        $query->having('total_amount_ngn', '>=', (float) $tier->min_amount);

        if ($tier->max_amount !== null) {
            $query->having('total_amount_ngn', '<=', (float) $tier->max_amount);
        }
    }

    private function applyDonorSearchFilter(\Illuminate\Database\Query\Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$this->escapeLike($search).'%';

        $query->havingRaw(
            '(display_sort_name LIKE ? OR donor_email LIKE ?)',
            [$like, $like]
        );
    }
}
