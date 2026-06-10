<?php

namespace App\Services\Admin\Dashboard;

use App\Enums\CampaignStatus;
use App\Enums\Currency;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard aggregates (overview, trends, breakdowns, active campaigns).
 *
 * Currency handling (query param `currency` on {@see DashboardFilterRequest}):
 *
 * - Omitted or empty: no transaction currency WHERE clause; sums use `amount_in_naira`
 *   so all successful donations roll up to one naira total. Response `currency` is NGN
 *   as a display label only; `currency_filter_applied` is false.
 * - Present (NGN, USD, GBP, EUR): only rows with `transactions.currency` matching;
 *   sums use native `amount`. `currency=NGN` means naira-denominated txs only, not
 *   “all currencies converted to naira”. `currency_filter_applied` is true.
 *
 * Pledges are never filtered by currency (date window only). Period comparison reuses
 * the same currency semantics on the previous equivalent date range.
 */
class DashboardService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        $query = $this->successfulTransactionsQuery($filters);
        $period = ListingFilterRules::resolveDateWindow($filters);
        $amountColumn = $this->amountColumn($filters);
        $current = $this->overviewMetrics($query, $amountColumn, $filters);
        $comparisonPeriod = $this->resolvePreviousEquivalentPeriod($period);
        $previous = $this->previousOverviewMetrics($filters, $comparisonPeriod, $amountColumn);

        return array_merge($this->responseContext($filters), [
            'currency' => $this->responseCurrency($filters),
            // Lets the UI distinguish naira rollup (false) from currency-specific totals (true).
            'currency_filter_applied' => $this->currencyFilterActive($filters),
            'total_fund_raised' => $current['total_fund_raised'],
            'total_transactions' => $current['total_transactions'],
            'total_pledges' => $current['total_pledges'],
            'total_donors' => $current['total_donors'],
            'total_fund_raised_change_percentage' => $this->changePercent($current['total_fund_raised_numeric'], $previous['total_fund_raised_numeric']),
            'total_fund_raised_change_direction' => $this->changeDirection($current['total_fund_raised_numeric'], $previous['total_fund_raised_numeric']),
            'total_transactions_change_percentage' => $this->changePercent($current['total_transactions_numeric'], $previous['total_transactions_numeric']),
            'total_transactions_change_direction' => $this->changeDirection($current['total_transactions_numeric'], $previous['total_transactions_numeric']),
            'total_pledges_change_percentage' => $this->changePercent($current['total_pledges_numeric'], $previous['total_pledges_numeric']),
            'total_pledges_change_direction' => $this->changeDirection($current['total_pledges_numeric'], $previous['total_pledges_numeric']),
            'total_donors_change_percentage' => $this->changePercent($current['total_donors_numeric'], $previous['total_donors_numeric']),
            'total_donors_change_direction' => $this->changeDirection($current['total_donors_numeric'], $previous['total_donors_numeric']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function campaignContributionTrend(array $filters): array
    {
        $hasYearFilter = array_key_exists('year', $filters);
        $year = isset($filters['year']) ? (int) $filters['year'] : null;
        $query = $hasYearFilter ? $this->successfulTransactionsQueryWithoutDateWindow($filters) : $this->successfulTransactionsQuery($filters);
        $amountColumn = $this->amountColumn($filters);

        if ($hasYearFilter) {
            $resolvedYear = $year ?? now()->year;
            $query->whereYear('created_at', $resolvedYear);

            $rows = $query
                ->selectRaw('MONTH(created_at) as month_num, SUM('.$amountColumn.') as total')
                ->groupBy(DB::raw('MONTH(created_at)'))
                ->orderBy(DB::raw('MONTH(created_at)'))
                ->get()
                ->keyBy(fn ($row) => (int) $row->month_num);

            $series = [];
            for ($m = 1; $m <= 12; $m++) {
                $series[] = [
                    'label' => Carbon::create()->month($m)->shortMonthName,
                    'value' => (string) ($rows[$m]->total ?? '0'),
                ];
            }

            return array_merge($this->responseContext($filters, includeComparison: false), [
                'currency' => $this->responseCurrency($filters),
                'currency_filter_applied' => $this->currencyFilterActive($filters),
                'year' => $resolvedYear,
                'series' => $series,
            ]);
        }

        $period = ListingFilterRules::resolveDateWindow($filters);
        $hasDateWindow = $period['start'] !== null || $period['end'] !== null;
        $bucket = $hasDateWindow ? '%Y-%m-%d' : '%Y-%m';

        $rows = $query
            ->selectRaw("DATE_FORMAT(created_at, '{$bucket}') as bucket, SUM(".$amountColumn.') as total')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '{$bucket}')"))
            ->orderBy('bucket')
            ->get();

        return array_merge($this->responseContext($filters), [
            'currency' => $this->responseCurrency($filters),
            'currency_filter_applied' => $this->currencyFilterActive($filters),
            'year' => null,
            'series' => $rows->map(fn ($row): array => [
                'label' => (string) $row->bucket,
                'value' => (string) $row->total,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function donationByUserType(array $filters): array
    {
        $query = $this->successfulTransactionsQuery($filters);
        $amountColumn = $this->amountColumn($filters);

        $rows = $query
            ->leftJoin('users', 'users.uuid', '=', 'transactions.user_uuid')
            ->leftJoin('donor_types', 'donor_types.uuid', '=', 'users.donor_type_uuid')
            ->selectRaw(
                "CASE
                    WHEN transactions.is_anonymous = 1 THEN 'Anonymous Donation'
                    WHEN donor_types.label IS NOT NULL AND donor_types.label != '' THEN donor_types.label
                    ELSE 'Public Donation'
                END as user_type,
                COUNT(transactions.id) as transactions_count,
                SUM(".$amountColumn.') as total_amount'
            )
            ->groupBy('user_type')
            ->orderByDesc('total_amount')
            ->get();

        return array_merge($this->responseContext($filters), [
            'items' => $rows->map(fn ($row): array => [
                'user_type' => (string) $row->user_type,
                'transactions_count' => (int) $row->transactions_count,
                'total_amount' => (string) $row->total_amount,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function donationByDonationType(array $filters): array
    {
        $query = $this->successfulTransactionsQuery($filters);
        $amountColumn = $this->amountColumn($filters);

        $rows = $query
            ->selectRaw(
                "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.donation_type')), ''), 'One Time Donation') as donation_type,
                COUNT(id) as transactions_count,
                SUM(".$amountColumn.') as total_amount'
            )
            ->groupBy('donation_type')
            ->orderByDesc('total_amount')
            ->get();

        return array_merge($this->responseContext($filters), [
            'items' => $rows->map(fn ($row): array => [
                'donation_type' => (string) $row->donation_type,
                'transactions_count' => (int) $row->transactions_count,
                'total_amount' => (string) $row->total_amount,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function donationByContributionTier(array $filters): array
    {
        $tiers = TierConfiguration::query()
            ->orderBy('sort_order')
            ->get(['uuid', 'name', 'min_amount', 'max_amount']);

        $result = [];
        foreach ($tiers as $tier) {
            $query = $this->successfulTransactionsQuery($filters);
            // Tier thresholds are configured in naira; currency filter only affects which txs count.
            $query->where('amount_in_naira', '>=', $tier->min_amount);
            if ($tier->max_amount !== null) {
                $query->where('amount_in_naira', '<=', $tier->max_amount);
            }

            $result[] = [
                'tier_id' => $tier->uuid,
                'tier_name' => $tier->name,
                'transactions_count' => (clone $query)->count(),
                'total_amount' => (string) ((clone $query)->sum($this->amountColumn($filters))),
            ];
        }

        return array_merge($this->responseContext($filters), [
            'items' => $result,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function activeCampaigns(array $filters): array
    {
        $currency = $this->responseCurrency($filters);
        $campaignQuery = Campaign::query()
            ->where('status', CampaignStatus::ACTIVE);

        // Without a currency filter, list every active campaign. With a filter, only
        // campaigns that accept donations in that currency (base or available list).
        if ($this->currencyFilterActive($filters)) {
            $campaignQuery->where(function (Builder $builder) use ($currency): void {
                $builder->where('base_currency', $currency)
                    ->orWhereJsonContains('available_donation_currencies', $currency);
            });
        }

        $campaigns = $campaignQuery->orderBy('name')->get([
            'uuid',
            'campaign_id',
            'name',
            'target_amount',
            'base_currency',
        ]);

        $campaignRows = $campaigns->map(function (Campaign $campaign) use ($filters, $currency): array {
            $tx = $this->successfulTransactionsQuery($filters)
                ->where('campaign_uuid', $campaign->uuid);
            // Reference totals ignore currency filter so clients can show naira/base breakdowns.
            $baseTx = $this->successfulTransactionsQueryWithoutCurrency($filters)
                ->where('campaign_uuid', $campaign->uuid);
            $raised = (float) (clone $tx)->sum($this->amountColumn($filters));
            $raisedInNaira = (float) (clone $baseTx)->sum('amount_in_naira');
            $raisedInBaseCurrency = $campaign->base_currency === Currency::NGN->value
                ? $raisedInNaira
                : (float) (clone $baseTx)
                    ->where('currency', $campaign->base_currency)
                    ->sum('amount');
            $target = (float) $campaign->target_amount;
            $hasTarget = $target > 0;

            return [
                'campaign_id' => $campaign->uuid,
                'public_campaign_code' => $campaign->campaign_id,
                'name' => $campaign->name,
                'target_amount' => (string) $campaign->target_amount,
                'filtered_currency' => $currency,
                'base_currency' => $campaign->base_currency,
                'raised_amount' => (string) $raised,
                'total_amount_raised_in_naira' => (string) $raisedInNaira,
                'total_amount_raised_in_base_currency' => (string) $raisedInBaseCurrency,
                'progress_percentage' => $hasTarget ? round(($raised / $target) * 100, 1) : null,
                'progress_status' => $hasTarget ? 'tracked' : 'no_target',
            ];
        })->values()->all();

        return array_merge($this->responseContext($filters), [
            'campaigns' => $campaignRows,
        ]);
    }

    /**
     * Period and comparison metadata shared across dashboard endpoints.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function responseContext(array $filters, bool $includeComparison = true): array
    {
        $period = ListingFilterRules::resolveDateWindow($filters);
        $comparisonPeriod = $includeComparison ? $this->resolvePreviousEquivalentPeriod($period) : null;

        return [
            'period' => $period['period'],
            'start_date' => $period['start']?->toDateString(),
            'end_date' => $period['end']?->toDateString(),
            'comparison' => $comparisonPeriod !== null ? 'previous_equivalent_period' : null,
            'comparison_days' => $comparisonPeriod !== null ? $comparisonPeriod['days'] : null,
            'comparison_start_date' => $comparisonPeriod !== null ? $comparisonPeriod['start']->toDateString() : null,
            'comparison_end_date' => $comparisonPeriod !== null ? $comparisonPeriod['end']->toDateString() : null,
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function uniqueDonorsCount(Builder $query): int
    {
        $userUuidCount = (clone $query)
            ->whereNotNull('user_uuid')
            ->distinct('user_uuid')
            ->count('user_uuid');

        $emailCount = (clone $query)
            ->whereNull('user_uuid')
            ->whereNotNull('donor_email')
            ->where('donor_email', '!=', '')
            ->distinct('donor_email')
            ->count('donor_email');

        $anonymousCount = (clone $query)
            ->whereNull('user_uuid')
            ->where(function (Builder $builder): void {
                $builder->whereNull('donor_email')
                    ->orWhere('donor_email', '');
            })
            ->count();

        return (int) ($userUuidCount + $emailCount + $anonymousCount);
    }

    /**
     * Successful transactions in the resolved date window, optionally scoped by currency.
     *
     * @param  array<string, mixed>  $filters  Validated dashboard filters (see class docblock).
     * @return Builder<Transaction>
     */
    private function successfulTransactionsQuery(array $filters): Builder
    {
        $query = Transaction::query()->countableTowardRevenue();

        if ($this->currencyFilterActive($filters)) {
            $query->where('transactions.currency', $this->resolvedCurrency($filters));
        }

        $range = ListingFilterRules::resolveDateWindow($filters);
        if ($range['start'] !== null) {
            $query->where('transactions.created_at', '>=', $range['start']);
        }
        if ($range['end'] !== null) {
            $query->where('transactions.created_at', '<=', $range['end']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function successfulTransactionsQueryWithoutDateWindow(array $filters): Builder
    {
        $query = Transaction::query()->countableTowardRevenue();

        if ($this->currencyFilterActive($filters)) {
            $query->where('currency', $this->resolvedCurrency($filters));
        }

        return $query;
    }

    /**
     * Same date window as {@see successfulTransactionsQuery()} but never filters by currency.
     * Used where the API exposes parallel naira / base-currency figures (e.g. active campaigns).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function successfulTransactionsQueryWithoutCurrency(array $filters): Builder
    {
        $query = Transaction::query()->countableTowardRevenue();

        $range = ListingFilterRules::resolveDateWindow($filters);
        if ($range['start'] !== null) {
            $query->where('transactions.created_at', '>=', $range['start']);
        }
        if ($range['end'] !== null) {
            $query->where('transactions.created_at', '<=', $range['end']);
        }

        return $query;
    }

    /**
     * Column to SUM for fund-raised metrics: naira rollup vs native donation amount.
     *
     * @param  array<string, mixed>  $filters
     */
    private function amountColumn(array $filters): string
    {
        if (! $this->currencyFilterActive($filters)) {
            return 'amount_in_naira';
        }

        return 'amount';
    }

    /**
     * True when the client sent a non-empty `currency` query param (after request normalization).
     * Absence means “all currencies, reported in naira” — not the same as `currency=NGN`.
     *
     * @param  array<string, mixed>  $filters
     */
    private function currencyFilterActive(array $filters): bool
    {
        if (! array_key_exists('currency', $filters)) {
            return false;
        }

        $currency = $filters['currency'];

        return $currency !== null && $currency !== '';
    }

    /**
     * `currency` field returned to the client. When no filter is active this is NGN even though
     * USD/GBP/EUR rows are included; pair with `currency_filter_applied` on overview/trend payloads.
     *
     * @param  array<string, mixed>  $filters
     */
    private function responseCurrency(array $filters): string
    {
        return $this->currencyFilterActive($filters)
            ? $this->resolvedCurrency($filters)
            : Currency::NGN->value;
    }

    /**
     * Normalized currency code when a filter is active; invalid values fall back to NGN.
     *
     * @param  array<string, mixed>  $filters
     */
    private function resolvedCurrency(array $filters): string
    {
        $currency = strtoupper((string) ($filters['currency'] ?? Currency::NGN->value));

        return in_array($currency, Currency::values(), true) ? $currency : Currency::NGN->value;
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return array<string, int|float|string>
     */
    private function overviewMetrics(Builder $query, string $amountColumn, array $filters): array
    {
        $fundRaised = (float) ((clone $query)->sum($amountColumn) ?? 0);
        $transactions = (int) ((clone $query)->count());
        $range = ListingFilterRules::resolveDateWindow($filters);
        $pledgeQ = Pledge::query();
        if ($range['start'] !== null) {
            $pledgeQ->where('created_at', '>=', $range['start']);
        }
        if ($range['end'] !== null) {
            $pledgeQ->where('created_at', '<=', $range['end']);
        }
        $pledges = (int) $pledgeQ->count();
        $donors = $this->uniqueDonorsCount((clone $query));

        return [
            'total_fund_raised' => (string) $fundRaised,
            'total_transactions' => $transactions,
            'total_pledges' => $pledges,
            'total_donors' => $donors,
            'total_fund_raised_numeric' => $fundRaised,
            'total_transactions_numeric' => $transactions,
            'total_pledges_numeric' => $pledges,
            'total_donors_numeric' => $donors,
        ];
    }

    /**
     * @param  array{start: Carbon|null, end: Carbon|null, period: string|null}  $period
     * @return array{days: int, start: Carbon, end: Carbon}|null
     */
    private function resolvePreviousEquivalentPeriod(array $period): ?array
    {
        if ($period['start'] === null || $period['end'] === null) {
            return null;
        }

        $inclusiveDays = (int) max(
            1,
            $period['start']->copy()->startOfDay()->diffInDays($period['end']->copy()->startOfDay()) + 1
        );
        $previousEnd = $period['start']->copy()->subDay()->endOfDay();
        $previousStart = $period['start']->copy()->subDays($inclusiveDays)->startOfDay();

        return [
            'days' => $inclusiveDays,
            'start' => $previousStart,
            'end' => $previousEnd,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{days: int, start: Carbon, end: Carbon}|null  $comparisonPeriod
     * @return array<string, int|float|string>
     */
    private function previousOverviewMetrics(array $filters, ?array $comparisonPeriod, string $amountColumn): array
    {
        if ($comparisonPeriod === null) {
            return [
                'total_fund_raised_numeric' => 0.0,
                'total_transactions_numeric' => 0,
                'total_pledges_numeric' => 0,
                'total_donors_numeric' => 0,
            ];
        }

        $previousFilters = $filters;
        $previousFilters['start_date'] = $comparisonPeriod['start']->toDateString();
        $previousFilters['end_date'] = $comparisonPeriod['end']->toDateString();
        $previousFilters['period'] = 'custom';

        return $this->overviewMetrics($this->successfulTransactionsQuery($previousFilters), $amountColumn, $previousFilters);
    }

    private function changePercent(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : 100.0;
        }

        return round(((($current - $previous) / $previous) * 100), 1);
    }

    private function changeDirection(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'increase';
        }
        if ($current < $previous) {
            return 'decrease';
        }

        return 'no_change';
    }
}
