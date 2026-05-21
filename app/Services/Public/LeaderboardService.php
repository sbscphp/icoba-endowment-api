<?php

namespace App\Services\Public;

use App\Enums\Currency;
use App\Models\Campaign;
use App\Models\GraduationSet;
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
        $search = trim((string) ($filters['search'] ?? ''));
        $amountColumn = $this->resolveAmountColumn($filters);

        $keySql = <<<'SQL'
CASE
  WHEN transactions.user_uuid IS NOT NULL THEN transactions.user_uuid
  WHEN transactions.donor_email IS NOT NULL AND transactions.donor_email != '' THEN LOWER(TRIM(transactions.donor_email))
  ELSE transactions.uuid
END
SQL;

        $base = Transaction::query()->countableTowardRevenue();

        if ($campaignUuid !== null && $campaignUuid !== '') {
            $base->where('transactions.campaign_uuid', $campaignUuid);
        }

        if ($mode === 'donor_type' && $donorTypeUuid !== null && $donorTypeUuid !== '') {
            $base->where(function (Builder $q) use ($donorTypeUuid): void {
                $q->where('transactions.donor_type_uuid', $donorTypeUuid)
                    ->orWhereHas('donor', fn (Builder $b) => $b->where('donor_type_uuid', $donorTypeUuid));
            });
        }

        if ($mode === 'set' && $setUuid !== null && $setUuid !== '') {
            $base->whereHas('donor', fn (Builder $b) => $b->where('graduation_set_uuid', $setUuid));
        }

        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $base->where(function (Builder $q) use ($like): void {
                $q->where('transactions.donor_name', 'like', $like)
                    ->orWhereHas('donor', function (Builder $b) use ($like): void {
                        $b->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like);
                    });
            });
        }

        $paginator = $base->clone()
            ->selectRaw('('.$keySql.') as donor_key')
            ->selectRaw('MAX(transactions.user_uuid) as user_uuid')
            ->selectRaw('MAX(transactions.donor_name) as donor_name')
            ->selectRaw('MAX(transactions.donor_email) as donor_email')
            ->selectRaw('MIN(transactions.is_anonymous) as all_anonymous')
            ->selectRaw('SUM(transactions.'.$amountColumn.') as total_amount')
            ->selectRaw('SUM(transactions.amount_in_naira) as total_amount_ngn')
            ->selectRaw('MAX(transactions.paid_at) as last_paid_at')
            ->groupBy(DB::raw('('.$keySql.')'))
            ->orderByDesc(DB::raw('SUM(transactions.'.$amountColumn.')'))
            ->paginate($perPage);

        $userUuids = $paginator->getCollection()->pluck('user_uuid')->filter()->unique()->all();
        $users = User::query()
            ->with(['graduationSet:uuid,name,set_number', 'donorType:uuid,label,slug'])
            ->whereIn('uuid', $userUuids)
            ->get()
            ->keyBy('uuid');

        $rank = ($paginator->currentPage() - 1) * $paginator->perPage();
        $paginator->getCollection()->transform(function ($row) use ($users, &$rank) {
            /** @var object $row */
            $rank++;

            return $this->mapAggregatedDonorRow($row, $users, $rank);
        });

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function setsLeaderboard(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $campaignUuid = isset($filters['campaign_uuid']) ? (string) $filters['campaign_uuid'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $amountColumn = $this->resolveAmountColumn($filters);
        $col = 'transactions.'.$amountColumn;

        $base = Transaction::query()->countableTowardRevenue()
            ->join('users', 'users.uuid', '=', 'transactions.user_uuid')
            ->whereNotNull('users.graduation_set_uuid');

        if ($campaignUuid !== null && $campaignUuid !== '') {
            $base->where('transactions.campaign_uuid', $campaignUuid);
        }

        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $base->whereExists(function ($q) use ($like): void {
                $q->selectRaw('1')
                    ->from('sets')
                    ->whereColumn('sets.uuid', 'users.graduation_set_uuid')
                    ->where(function ($b) use ($like): void {
                        $b->where('sets.name', 'like', $like)
                            ->orWhere('sets.set_number', 'like', $like);
                    });
            });
        }

        $paginator = $base->clone()
            ->selectRaw('users.graduation_set_uuid as set_uuid')
            ->selectRaw('SUM('.$col.') as total_amount')
            ->groupBy('users.graduation_set_uuid')
            ->orderByDesc(DB::raw('SUM('.$col.')'))
            ->paginate($perPage);

        $setIds = $paginator->getCollection()->pluck('set_uuid')->filter()->unique()->all();
        $sets = GraduationSet::query()->whereIn('uuid', $setIds)->get()->keyBy('uuid');

        $rank = ($paginator->currentPage() - 1) * $paginator->perPage();
        $paginator->getCollection()->transform(function ($row) use ($sets, &$rank) {
            /** @var object $row */
            $rank++;
            $set = $sets[(string) $row->set_uuid] ?? null;

            return [
                'rank' => $rank,
                'graduation_set_uuid' => (string) $row->set_uuid,
                'set_name' => $set !== null ? $set->name : null,
                'set_number' => $set !== null ? $set->set_number : null,
                'total_amount' => (string) $row->total_amount,
            ];
        });

        return $paginator;
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

        return $q->get()->map(fn (Transaction $tx) => $this->mapTransactionForPublic($tx));
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
            'currency' => $this->resolveDisplayCurrency($filters),
            'raised' => (string) $raised,
            'target' => (string) $target,
            'percent' => $percent,
        ];
    }

    /**
     * @param  Collection<string, User>  $users
     * @return array<string, mixed>
     */
    private function mapAggregatedDonorRow(object $row, Collection $users, int $rank): array
    {
        $totalNgn = (float) ($row->total_amount_ngn ?? 0);
        $tierLabel = $this->tierResolution->resolveDisplayLabelForCumulativeAmount($totalNgn);
        $allAnonymous = (int) ($row->all_anonymous ?? 0) === 1;

        $payload = [
            'rank' => $rank,
            'total_amount' => (string) $row->total_amount,
            'tier_label' => $tierLabel,
            'last_donation_at' => $row->last_paid_at,
        ];

        if ($allAnonymous) {
            $payload['display_name'] = 'Anonymous';

            return $payload;
        }

        if (! empty($row->user_uuid)) {
            $user = $users[(string) $row->user_uuid] ?? null;
            if ($user !== null) {
                $payload['display_name'] = trim(implode(' ', array_filter([(string) $user->firstname, (string) $user->lastname])));
                $payload['donor_type_label'] = $user->donorType?->label;
                $payload['set_label'] = $user->graduationSet?->name;
            } else {
                $payload['display_name'] = (string) ($row->donor_name ?? 'Donor');
            }
        } else {
            $payload['display_name'] = (string) ($row->donor_name ?: 'Donor');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTransactionForPublic(Transaction $tx): array
    {
        $tier = $this->tierResolution->resolveDisplayLabelForAmount(
            $tx->amount_in_naira !== null ? (float) $tx->amount_in_naira : null
        );

        $row = [
            'transaction_uuid' => $tx->uuid,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'paid_at' => $tx->paid_at,
            'tier_label' => $tier,
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
}
