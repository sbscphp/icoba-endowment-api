<?php

namespace App\Services\Admin\TierConfiguration;

use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Services\Donation\DonorCumulativeTotalService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TierConfigurationMemberStatsService
{
    private const MEMBER_PERIOD_SQL = 'COALESCE(transactions.paid_at, transactions.created_at)';

    private const CONTRIBUTION_PERIOD_SQL = 'transactions.created_at';

    public function __construct(
        private readonly DonorCumulativeTotalService $cumulativeTotal,
    ) {}

    /**
     * @param  iterable<int, TierConfiguration>  $tiers
     * @return array<string, array{
     *     members_count: int,
     *     registered_users_count: int,
     *     guest_count: int,
     *     contribution_by_tier: float,
     * }>
     */
    public function forTiers(iterable $tiers, ?CarbonInterface $start = null, ?CarbonInterface $end = null): array
    {
        $tierList = collect($tiers)->values();
        if ($tierList->isEmpty()) {
            return [];
        }

        $stats = $this->emptyStats($tierList);

        $this->applyContributionStats($stats, $tierList, $start, $end);
        $this->applyMemberStats($stats, $tierList, $start, $end);

        return $stats;
    }

    /**
     * @param  iterable<int, TierConfiguration>  $tiers
     */
    public function attachToTiers(iterable $tiers, ?CarbonInterface $start = null, ?CarbonInterface $end = null): void
    {
        $stats = $this->forTiers($tiers, $start, $end);

        foreach ($tiers as $tier) {
            $row = $stats[$tier->uuid] ?? [
                'members_count' => 0,
                'registered_users_count' => 0,
                'guest_count' => 0,
                'contribution_by_tier' => 0.0,
            ];

            $tier->setAttribute('members_count', $row['members_count']);
            $tier->setAttribute('registered_users_count', $row['registered_users_count']);
            $tier->setAttribute('guest_count', $row['guest_count']);
            $tier->setAttribute('contribution_by_tier', $row['contribution_by_tier']);
        }
    }

    /**
     * @param  Collection<int, TierConfiguration>  $tierList
     * @param  array<string, array{members_count: int, registered_users_count: int, guest_count: int, contribution_by_tier: float}>  $stats
     */
    private function applyContributionStats(array &$stats, Collection $tierList, ?CarbonInterface $start, ?CarbonInterface $end): void
    {
        $effectiveNgnSql = $this->cumulativeTotal->effectiveAmountNgnSql('transactions');
        $selects = [];

        foreach ($tierList as $index => $tier) {
            $band = $this->tierBandSql('('.$effectiveNgnSql.')', $tier);
            $selects[] = 'COALESCE(SUM(CASE WHEN '.$band.' THEN ('.$effectiveNgnSql.') ELSE 0 END), 0) AS contribution_'.$index;
        }

        $query = Transaction::query()->countableTowardRevenue();
        $this->applyPeriod($query, $start, $end, self::CONTRIBUTION_PERIOD_SQL);

        $row = $query->selectRaw(implode(', ', $selects))->toBase()->first();
        if ($row === null) {
            return;
        }

        foreach ($tierList as $index => $tier) {
            $stats[$tier->uuid]['contribution_by_tier'] = round((float) ($row->{'contribution_'.$index} ?? 0), 2);
        }
    }

    /**
     * @param  Collection<int, TierConfiguration>  $tierList
     * @param  array<string, array{members_count: int, registered_users_count: int, guest_count: int, contribution_by_tier: float}>  $stats
     */
    private function applyMemberStats(array &$stats, Collection $tierList, ?CarbonInterface $start, ?CarbonInterface $end): void
    {
        $selects = [];

        foreach ($tierList as $index => $tier) {
            $band = $this->tierBandSql('donors.total_amount_ngn', $tier);
            $selects[] = 'COALESCE(SUM(CASE WHEN '.$band.' THEN 1 ELSE 0 END), 0) AS members_'.$index;
            $selects[] = 'COALESCE(SUM(CASE WHEN '.$band.' AND (donors.identity_user_uuid IS NOT NULL OR donors.user_uuid IS NOT NULL) THEN 1 ELSE 0 END), 0) AS registered_'.$index;
            $selects[] = 'COALESCE(SUM(CASE WHEN '.$band.' AND donors.identity_user_uuid IS NULL AND donors.user_uuid IS NULL THEN 1 ELSE 0 END), 0) AS guest_'.$index;
        }

        $row = DB::query()
            ->fromSub($this->donorAggregateSubquery($start, $end), 'donors')
            ->selectRaw(implode(', ', $selects))
            ->first();

        if ($row === null) {
            return;
        }

        foreach ($tierList as $index => $tier) {
            $stats[$tier->uuid]['members_count'] = (int) ($row->{'members_'.$index} ?? 0);
            $stats[$tier->uuid]['registered_users_count'] = (int) ($row->{'registered_'.$index} ?? 0);
            $stats[$tier->uuid]['guest_count'] = (int) ($row->{'guest_'.$index} ?? 0);
        }
    }

    /**
     * @param  Collection<int, TierConfiguration>  $tierList
     * @return array<string, array{members_count: int, registered_users_count: int, guest_count: int, contribution_by_tier: float}>
     */
    private function emptyStats(Collection $tierList): array
    {
        $stats = [];
        foreach ($tierList as $tier) {
            $stats[$tier->uuid] = [
                'members_count' => 0,
                'registered_users_count' => 0,
                'guest_count' => 0,
                'contribution_by_tier' => 0.0,
            ];
        }

        return $stats;
    }

    private function donorAggregateSubquery(?CarbonInterface $start, ?CarbonInterface $end): QueryBuilder
    {
        $keySql = DonorCumulativeTotalService::DONOR_KEY_SQL;
        $effectiveNgnSql = $this->cumulativeTotal->effectiveAmountNgnSql('transactions');

        $inner = Transaction::query()
            ->countableTowardRevenue()
            ->where('transactions.is_anonymous', false)
            ->leftJoin('giving_identities', 'giving_identities.uuid', '=', 'transactions.giving_identity_uuid');
        $this->applyPeriod($inner, $start, $end, self::MEMBER_PERIOD_SQL);

        $inner
            ->selectRaw('('.$keySql.') AS donor_key')
            ->selectRaw('('.$effectiveNgnSql.') AS effective_amount_ngn')
            ->selectRaw('transactions.user_uuid AS user_uuid')
            ->selectRaw('giving_identities.user_uuid AS identity_user_uuid');

        return DB::query()
            ->fromSub($inner->toBase(), 'donor_transactions')
            ->select('donor_key')
            ->selectRaw('COALESCE(SUM(effective_amount_ngn), 0) AS total_amount_ngn')
            ->selectRaw('MAX(user_uuid) AS user_uuid')
            ->selectRaw('MAX(identity_user_uuid) AS identity_user_uuid')
            ->groupBy('donor_key');
    }

    private function tierBandSql(string $amountSql, TierConfiguration $tier): string
    {
        $band = $amountSql.' >= '.$this->sqlAmount((float) $tier->min_amount);

        if ($tier->max_amount !== null) {
            $band .= ' AND '.$amountSql.' <= '.$this->sqlAmount((float) $tier->max_amount);
        }

        return $band;
    }

    private function sqlAmount(float $amount): string
    {
        return sprintf('%.2f', $amount);
    }

    private function applyPeriod(Builder $query, ?CarbonInterface $start, ?CarbonInterface $end, string $columnSql): void
    {
        if ($start !== null) {
            $query->whereRaw($columnSql.' >= ?', [$start]);
        }

        if ($end !== null) {
            $query->whereRaw($columnSql.' <= ?', [$end]);
        }
    }
}
