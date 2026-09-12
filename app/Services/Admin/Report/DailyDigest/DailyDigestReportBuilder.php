<?php

namespace App\Services\Admin\Report\DailyDigest;

use App\Enums\CampaignStatus;
use App\Enums\Currency;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the full dataset for the admin daily digest report.
 *
 * All money figures are naira (NGN) unless the key says otherwise. Donation
 * figures use `transactions.created_at` and `amount_in_naira`, matching the
 * admin dashboard. Pledge figures come from the pledge schedule (installments),
 * so "honored" and "pending" match what donors see on their own pledge pages.
 *
 * Overdue definition: an installment whose due date is on or before the report
 * date and still has a remaining balance, on an active pledge that is not paused.
 * Partially paid installments count as overdue.
 */
class DailyDigestReportBuilder
{
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly PledgeScheduleService $scheduleService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?CarbonInterface $reportDate = null): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $reportDate = ($reportDate?->copy() ?? now($timezone)->subDay())->setTimezone($timezone)->startOfDay();
        $reportDayEnd = $reportDate->copy()->endOfDay();

        $trendDays = max(7, (int) config('reports.daily_digest.trend_days', 14));
        $trendMonths = max(1, (int) config('reports.daily_digest.trend_months', 6));
        $upcomingDays = max(1, (int) config('reports.daily_digest.upcoming_days', 7));

        $dailySeries = $this->dailyDonationSeries($reportDate, $trendDays, $trendMonths);
        $pledgeWalk = $this->walkActivePledges($reportDate, $upcomingDays);
        $campaigns = $this->campaignRows($reportDate, $pledgeWalk['by_campaign']);

        $yesterday = $this->donationTotals($reportDate, $reportDayEnd);
        $mtd = $this->donationTotals($reportDate->copy()->startOfMonth(), $reportDayEnd);
        $ytd = $this->donationTotals($reportDate->copy()->startOfYear(), $reportDayEnd);
        $allTime = $this->donationTotals(null, $reportDayEnd);

        return [
            'report_date' => $reportDate->toDateString(),
            'report_date_label' => $reportDate->format('l, F j, Y'),
            'generated_at' => now($timezone),
            'timezone' => $timezone,
            'currency' => Currency::NGN->value,
            'summary' => [
                'donations_yesterday' => $yesterday,
                'donations_mtd' => $mtd,
                'donations_ytd' => $ytd,
                'donations_all_time' => $allTime,
                'new_pledges_yesterday' => $this->newPledgeTotals($reportDate, $reportDayEnd),
                'pledges_fulfilled_yesterday' => $this->fulfilledPledgeCount($reportDate, $reportDayEnd),
                'active_pledges' => $pledgeWalk['active_totals'],
                'overdue' => $pledgeWalk['overdue']['totals'],
                'paused_pledges' => count($pledgeWalk['paused']),
                'awaiting_bank_verification' => $this->awaitingBankVerification($reportDayEnd),
            ],
            'trend' => [
                'daily' => $dailySeries['daily'],
                'monthly' => $dailySeries['monthly'],
                'daily_average' => $this->money($this->average(array_column($dailySeries['daily'], 'amount_numeric'))),
                'peak_day' => $this->peak($dailySeries['daily']),
            ],
            'variance' => $this->variance($reportDate, $dailySeries['by_date'], $mtd),
            'campaigns' => $campaigns,
            'breakdowns' => [
                'payment_method' => $this->breakdownByPaymentMethod($reportDate, $reportDayEnd),
                'currency' => $this->breakdownByCurrency($reportDate, $reportDayEnd),
                'donor_type' => $this->breakdownByDonorType($reportDate, $reportDayEnd),
            ],
            'overdue' => $pledgeWalk['overdue'],
            'upcoming' => $pledgeWalk['upcoming'],
            'paused' => $pledgeWalk['paused'],
            'new_pledges' => $this->newPledgeRows($reportDate, $reportDayEnd),
            'fulfilled_pledges' => $this->fulfilledPledgeRows($reportDate, $reportDayEnd),
        ];
    }

    // ---------------------------------------------------------------------
    // Donations
    // ---------------------------------------------------------------------

    /**
     * @return Builder<Transaction>
     */
    private function revenueQuery(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        $query = Transaction::query()->countableTowardRevenue();
        if ($from !== null) {
            $query->where('transactions.created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('transactions.created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @return array{count:int, amount:string, amount_numeric:float, donors:int}
     */
    private function donationTotals(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $query = $this->revenueQuery($from, $to);
        $amount = (float) ((clone $query)->sum('amount_in_naira') ?? 0);

        return [
            'count' => (int) (clone $query)->count(),
            'amount' => $this->money($amount),
            'amount_numeric' => $amount,
            'donors' => $this->uniqueDonorsCount($query),
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function uniqueDonorsCount(Builder $query): int
    {
        $registered = (clone $query)->whereNotNull('user_uuid')->distinct()->count('user_uuid');
        $guests = (clone $query)->whereNull('user_uuid')->whereNotNull('donor_email')->where('donor_email', '!=', '')->distinct()->count('donor_email');
        $anonymous = (clone $query)->whereNull('user_uuid')->where(function (Builder $b): void {
            $b->whereNull('donor_email')->orWhere('donor_email', '');
        })->count();

        return (int) ($registered + $guests + $anonymous);
    }

    /**
     * One query grouped by calendar day covering the monthly window; daily and
     * monthly series are derived from it in PHP so the SQL stays portable.
     *
     * @return array{daily:list<array<string,mixed>>, monthly:list<array<string,mixed>>, by_date:array<string,array{count:int,amount:float}>}
     */
    private function dailyDonationSeries(CarbonInterface $reportDate, int $trendDays, int $trendMonths): array
    {
        $monthlyStart = $reportDate->copy()->startOfMonth()->subMonths($trendMonths - 1);
        $dailyStart = $reportDate->copy()->subDays($trendDays - 1);
        $windowStart = $monthlyStart->lt($dailyStart) ? $monthlyStart : $dailyStart;
        // Include one extra week before the daily window so "same weekday last week" can be read from the same map.
        $windowStart = $windowStart->copy()->subDays(7)->startOfDay();

        $rows = $this->revenueQuery($windowStart, $reportDate->copy()->endOfDay())
            ->selectRaw('DATE(transactions.created_at) as day, COUNT(*) as tx_count, SUM(amount_in_naira) as total')
            ->groupBy(DB::raw('DATE(transactions.created_at)'))
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row->day] = ['count' => (int) $row->tx_count, 'amount' => (float) $row->total];
        }

        $daily = [];
        for ($i = $trendDays - 1; $i >= 0; $i--) {
            $day = $reportDate->copy()->subDays($i);
            $key = $day->toDateString();
            $amount = $byDate[$key]['amount'] ?? 0.0;
            $daily[] = [
                'date' => $key,
                'label' => $day->format('D j M'),
                'count' => $byDate[$key]['count'] ?? 0,
                'amount' => $this->money($amount),
                'amount_numeric' => $amount,
            ];
        }

        $monthly = [];
        for ($i = $trendMonths - 1; $i >= 0; $i--) {
            $month = $reportDate->copy()->startOfMonth()->subMonths($i);
            $prefix = $month->format('Y-m');
            $count = 0;
            $amount = 0.0;
            foreach ($byDate as $date => $stats) {
                if (str_starts_with($date, $prefix)) {
                    $count += $stats['count'];
                    $amount += $stats['amount'];
                }
            }
            $monthly[] = [
                'month' => $prefix,
                'label' => $month->format('M Y'),
                'count' => $count,
                'amount' => $this->money($amount),
                'amount_numeric' => $amount,
                'is_partial' => $prefix === $reportDate->format('Y-m'),
            ];
        }

        return ['daily' => $daily, 'monthly' => $monthly, 'by_date' => $byDate];
    }

    /**
     * @param  array<string,array{count:int,amount:float}>  $byDate
     * @param  array{count:int, amount:string, amount_numeric:float, donors:int}  $mtd
     * @return array<string, array<string, mixed>>
     */
    private function variance(CarbonInterface $reportDate, array $byDate, array $mtd): array
    {
        $amountOn = fn (CarbonInterface $day): float => $byDate[$day->toDateString()]['amount'] ?? 0.0;
        $countOn = fn (CarbonInterface $day): int => $byDate[$day->toDateString()]['count'] ?? 0;

        $current = $amountOn($reportDate);
        $previousDay = $reportDate->copy()->subDay();
        $sameWeekdayLastWeek = $reportDate->copy()->subWeek();

        $sevenDayWindow = [];
        for ($i = 1; $i <= 7; $i++) {
            $sevenDayWindow[] = $amountOn($reportDate->copy()->subDays($i));
        }
        $sevenDayAverage = $this->average($sevenDayWindow);

        // Previous month to date: same day-of-month span, clamped to the shorter month.
        $previousMonthStart = $reportDate->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $previousMonthStart->copy()->day(min($reportDate->day, $previousMonthStart->daysInMonth))->endOfDay();
        $previousMtd = $this->donationTotals($previousMonthStart, $previousMonthEnd);

        return [
            'vs_previous_day' => $this->compare(
                'Yesterday vs. the day before',
                $current,
                $amountOn($previousDay),
                $countOn($reportDate),
                $countOn($previousDay),
                $previousDay->format('D j M'),
            ),
            'vs_7_day_average' => $this->compare(
                'Yesterday vs. 7-day daily average',
                $current,
                $sevenDayAverage,
                $countOn($reportDate),
                (int) round($this->average(array_map(fn (int $i): int => $countOn($reportDate->copy()->subDays($i)), range(1, 7)))),
                $reportDate->copy()->subDays(7)->format('j M').' – '.$previousDay->format('j M'),
            ),
            'vs_same_weekday_last_week' => $this->compare(
                'Yesterday vs. same weekday last week',
                $current,
                $amountOn($sameWeekdayLastWeek),
                $countOn($reportDate),
                $countOn($sameWeekdayLastWeek),
                $sameWeekdayLastWeek->format('D j M'),
            ),
            'mtd_vs_previous_mtd' => $this->compare(
                'Month to date vs. previous month to date',
                $mtd['amount_numeric'],
                $previousMtd['amount_numeric'],
                $mtd['count'],
                $previousMtd['count'],
                $previousMonthStart->format('j M').' – '.$previousMonthEnd->format('j M Y'),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(string $label, float $current, float $previous, int $currentCount, int $previousCount, string $comparisonLabel): array
    {
        $difference = $current - $previous;

        return [
            'label' => $label,
            'comparison_label' => $comparisonLabel,
            'current' => $this->money($current),
            'previous' => $this->money($previous),
            'difference' => $this->money($difference),
            'difference_numeric' => round($difference, 2),
            'percent' => $this->changePercent($current, $previous),
            'direction' => $this->changeDirection($current, $previous),
            'current_count' => $currentCount,
            'previous_count' => $previousCount,
            'count_difference' => $currentCount - $previousCount,
        ];
    }

    // ---------------------------------------------------------------------
    // Breakdowns (yesterday + month to date)
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function breakdownByPaymentMethod(CarbonInterface $reportDate, CarbonInterface $reportDayEnd): array
    {
        $label = function (?string $gateway, mixed $applicationType): string {
            $applicationType = $applicationType instanceof \BackedEnum ? $applicationType->value : $applicationType;
            if ($applicationType === TransactionApplicationType::BANK_TRANSFER->value) {
                return 'Bank transfer';
            }
            if ($applicationType === TransactionApplicationType::ADMIN_LINKED_PAYMENT->value
                || $applicationType === TransactionApplicationType::ADMIN_ADJUSTMENT->value) {
                return 'Admin recorded';
            }
            $gateway = strtolower(trim((string) $gateway));

            return $gateway !== '' ? Str::title($gateway) : 'Other';
        };

        $fetch = function (CarbonInterface $from, CarbonInterface $to) use ($label): array {
            $out = [];
            $rows = $this->revenueQuery($from, $to)
                ->selectRaw('gateway, application_type, COUNT(*) as tx_count, SUM(amount_in_naira) as total')
                ->groupBy('gateway', 'application_type')
                ->get();
            foreach ($rows as $row) {
                $key = $label($row->gateway, $row->application_type);
                $out[$key] ??= ['count' => 0, 'amount' => 0.0];
                $out[$key]['count'] += (int) $row->tx_count;
                $out[$key]['amount'] += (float) $row->total;
            }

            return $out;
        };

        return $this->mergeBreakdown(
            $fetch($reportDate, $reportDayEnd),
            $fetch($reportDate->copy()->startOfMonth(), $reportDayEnd),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function breakdownByCurrency(CarbonInterface $reportDate, CarbonInterface $reportDayEnd): array
    {
        $fetch = function (CarbonInterface $from, CarbonInterface $to): array {
            $out = [];
            $rows = $this->revenueQuery($from, $to)
                ->selectRaw('currency, COUNT(*) as tx_count, SUM(amount) as native_total, SUM(amount_in_naira) as total')
                ->groupBy('currency')
                ->get();
            foreach ($rows as $row) {
                $key = strtoupper((string) $row->currency);
                $out[$key] = [
                    'count' => (int) $row->tx_count,
                    'amount' => (float) $row->total,
                    'native_amount' => (float) $row->native_total,
                ];
            }

            return $out;
        };

        return $this->mergeBreakdown(
            $fetch($reportDate, $reportDayEnd),
            $fetch($reportDate->copy()->startOfMonth(), $reportDayEnd),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function breakdownByDonorType(CarbonInterface $reportDate, CarbonInterface $reportDayEnd): array
    {
        $fetch = function (CarbonInterface $from, CarbonInterface $to): array {
            $out = [];
            $rows = $this->revenueQuery($from, $to)
                ->leftJoin('users', 'users.uuid', '=', 'transactions.user_uuid')
                ->leftJoin('donor_types', 'donor_types.uuid', '=', 'users.donor_type_uuid')
                ->selectRaw(
                    "CASE
                        WHEN transactions.is_anonymous = 1 THEN 'Anonymous'
                        WHEN donor_types.label IS NOT NULL AND donor_types.label != '' THEN donor_types.label
                        ELSE 'Public / guest'
                    END as donor_type,
                    COUNT(transactions.id) as tx_count,
                    SUM(transactions.amount_in_naira) as total"
                )
                ->groupBy('donor_type')
                ->get();
            foreach ($rows as $row) {
                $out[(string) $row->donor_type] = ['count' => (int) $row->tx_count, 'amount' => (float) $row->total];
            }

            return $out;
        };

        return $this->mergeBreakdown(
            $fetch($reportDate, $reportDayEnd),
            $fetch($reportDate->copy()->startOfMonth(), $reportDayEnd),
        );
    }

    /**
     * @param  array<string, array{count:int, amount:float, native_amount?:float}>  $yesterday
     * @param  array<string, array{count:int, amount:float, native_amount?:float}>  $mtd
     * @return list<array<string,mixed>>
     */
    private function mergeBreakdown(array $yesterday, array $mtd): array
    {
        $labels = array_unique(array_merge(array_keys($yesterday), array_keys($mtd)));
        $rows = [];
        foreach ($labels as $label) {
            $y = $yesterday[$label] ?? ['count' => 0, 'amount' => 0.0];
            $m = $mtd[$label] ?? ['count' => 0, 'amount' => 0.0];
            $row = [
                'label' => $label,
                'yesterday_count' => $y['count'],
                'yesterday_amount' => $this->money($y['amount']),
                'mtd_count' => $m['count'],
                'mtd_amount' => $this->money($m['amount']),
                'mtd_amount_numeric' => $m['amount'],
            ];
            if (array_key_exists('native_amount', $y) || array_key_exists('native_amount', $m)) {
                $row['yesterday_native_amount'] = $this->money($y['native_amount'] ?? 0.0);
                $row['mtd_native_amount'] = $this->money($m['native_amount'] ?? 0.0);
            }
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $b['mtd_amount_numeric'] <=> $a['mtd_amount_numeric']);

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Pledges
    // ---------------------------------------------------------------------

    /**
     * Single pass over active pledges producing the overdue list (grouped by
     * donor), campaign pledge aggregates, upcoming installments and paused list.
     *
     * @return array<string, mixed>
     */
    private function walkActivePledges(CarbonInterface $reportDate, int $upcomingDays): array
    {
        $reportDateString = $reportDate->toDateString();
        $upcomingEnd = $reportDate->copy()->addDays($upcomingDays)->toDateString();

        $donorGroups = [];
        $byCampaign = [];
        $upcoming = [];
        $paused = [];
        $activeTotals = ['count' => 0, 'committed' => 0.0, 'honored' => 0.0, 'pending' => 0.0, 'donors' => []];
        $aging = [
            '1-7 days' => ['min' => 1, 'max' => 7, 'count' => 0, 'amount' => 0.0],
            '8-30 days' => ['min' => 8, 'max' => 30, 'count' => 0, 'amount' => 0.0],
            '31-60 days' => ['min' => 31, 'max' => 60, 'count' => 0, 'amount' => 0.0],
            '61-90 days' => ['min' => 61, 'max' => 90, 'count' => 0, 'amount' => 0.0],
            'Over 90 days' => ['min' => 91, 'max' => null, 'count' => 0, 'amount' => 0.0],
        ];

        Pledge::query()
            ->where('status', PledgeStatus::ACTIVE)
            ->with([
                'campaign:uuid,name,campaign_id,status',
                'donor:uuid,firstname,lastname,organization_name,email,phone_number,donor_type_uuid,graduation_set_uuid',
                'donor.donorType:uuid,label',
                'donor.graduationSet:uuid,name',
                'donorType:uuid,label',
                'graduationSet:uuid,name',
            ])
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $pledges) use (
                $reportDateString,
                $upcomingEnd,
                $reportDate,
                &$donorGroups,
                &$byCampaign,
                &$upcoming,
                &$paused,
                &$activeTotals,
                &$aging,
            ): void {
                $schedules = $this->scheduleService->buildForPledges($pledges);
                $lastPayments = $this->lastPaymentDates($pledges->pluck('uuid')->all());

                foreach ($pledges as $pledge) {
                    $schedule = $schedules[$pledge->uuid] ?? null;
                    if (! is_array($schedule)) {
                        continue;
                    }

                    $items = $schedule['items'] ?? [];
                    $committedNgn = (float) ($pledge->committed_amount_ngn ?? array_sum(array_column($items, 'pledged_amount_ngn')));
                    $honored = array_sum(array_map(fn (array $i): float => (float) $i['paid_amount'], $items));
                    $honoredNgn = array_sum(array_map(fn (array $i): float => (float) $i['paid_amount_ngn'], $items));
                    $pending = array_sum(array_map(fn (array $i): float => (float) $i['remaining_amount'], $items));
                    $pendingNgn = array_sum(array_map(fn (array $i): float => (float) $i['remaining_amount_ngn'], $items));
                    $isPaused = $this->scheduleService->isPledgePaused($pledge);
                    $donorKey = $this->donorGroupKey($pledge);

                    $activeTotals['count']++;
                    $activeTotals['committed'] += $committedNgn;
                    $activeTotals['honored'] += $honoredNgn;
                    $activeTotals['pending'] += $pendingNgn;
                    $activeTotals['donors'][$donorKey] = true;

                    $campaignKey = (string) $pledge->campaign_uuid;
                    $byCampaign[$campaignKey] ??= [
                        'pledges' => 0, 'committed' => 0.0, 'honored' => 0.0, 'pending' => 0.0,
                        'scheduled_to_date' => 0.0, 'overdue' => 0.0, 'overdue_pledges' => 0, 'paused' => 0,
                    ];
                    $byCampaign[$campaignKey]['pledges']++;
                    $byCampaign[$campaignKey]['committed'] += $committedNgn;
                    $byCampaign[$campaignKey]['honored'] += $honoredNgn;
                    $byCampaign[$campaignKey]['pending'] += $pendingNgn;

                    $overdueItems = [];
                    $overdueNgn = 0.0;
                    $overdueNative = 0.0;
                    foreach ($items as $item) {
                        $due = $item['due_date'] ?? null;
                        $remaining = (float) $item['remaining_amount'];
                        $remainingNgn = (float) $item['remaining_amount_ngn'];
                        if (! is_string($due) || $due === '') {
                            continue;
                        }

                        if ($due <= $reportDateString) {
                            $byCampaign[$campaignKey]['scheduled_to_date'] += (float) $item['pledged_amount_ngn'];
                        }

                        if ($remaining <= self::EPSILON) {
                            continue;
                        }

                        if (! $isPaused && $due <= $reportDateString) {
                            $daysOverdue = (int) Carbon::parse($due)->startOfDay()->diffInDays($reportDate) + 1;
                            $overdueItems[] = [
                                'sequence' => (int) $item['sequence'],
                                'due_date' => $due,
                                'days_overdue' => $daysOverdue,
                                'pledged_amount' => $item['pledged_amount'],
                                'paid_amount' => $item['paid_amount'],
                                'remaining_amount' => $item['remaining_amount'],
                                'remaining_amount_ngn' => $item['remaining_amount_ngn'],
                                'status' => $item['status'],
                            ];
                            $overdueNgn += $remainingNgn;
                            $overdueNative += $remaining;
                            $this->addToAging($aging, $daysOverdue, $remainingNgn);
                        } elseif (! $isPaused && $due > $reportDateString && $due <= $upcomingEnd) {
                            $upcoming[] = [
                                'due_date' => $due,
                                'sequence' => (int) $item['sequence'],
                                'amount' => $item['remaining_amount'],
                                'amount_ngn' => $item['remaining_amount_ngn'],
                                'currency' => $pledge->currency,
                                'campaign' => $pledge->campaign?->name,
                                'donor' => $this->donorContact($pledge),
                                'pledge_uuid' => $pledge->uuid,
                            ];
                        }
                    }

                    if ($isPaused) {
                        $byCampaign[$campaignKey]['paused']++;
                        $paused[] = [
                            'pledge_uuid' => $pledge->uuid,
                            'campaign' => $pledge->campaign?->name,
                            'donor' => $this->donorContact($pledge),
                            'committed' => $this->money((float) $pledge->committed_amount),
                            'currency' => $pledge->currency,
                            'pending_ngn' => $this->money($pendingNgn),
                            'paused_at' => $this->dateOnly($this->scheduleService->pledgePausedAt($pledge)),
                            'resume_date' => $this->scheduleService->pledgeResumeDate($pledge),
                        ];
                    }

                    if ($overdueItems === []) {
                        continue;
                    }

                    $byCampaign[$campaignKey]['overdue'] += $overdueNgn;
                    $byCampaign[$campaignKey]['overdue_pledges']++;

                    usort($overdueItems, fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']));
                    $earliest = $overdueItems[0];
                    $reminders = data_get($pledge->metadata, 'payment_reminders_sent', []);

                    $donorGroups[$donorKey] ??= [
                        'donor' => $this->donorContact($pledge),
                        'totals' => ['pledges' => 0, 'committed_ngn' => 0.0, 'honored_ngn' => 0.0, 'pending_ngn' => 0.0, 'overdue_ngn' => 0.0, 'overdue_installments' => 0, 'max_days_overdue' => 0],
                        'pledges' => [],
                    ];
                    $group = &$donorGroups[$donorKey];
                    $group['totals']['pledges']++;
                    $group['totals']['committed_ngn'] += $committedNgn;
                    $group['totals']['honored_ngn'] += $honoredNgn;
                    $group['totals']['pending_ngn'] += $pendingNgn;
                    $group['totals']['overdue_ngn'] += $overdueNgn;
                    $group['totals']['overdue_installments'] += count($overdueItems);
                    $group['totals']['max_days_overdue'] = max($group['totals']['max_days_overdue'], $earliest['days_overdue']);
                    $group['pledges'][] = [
                        'pledge_uuid' => $pledge->uuid,
                        'campaign' => $pledge->campaign?->name ?? 'General Endowment Fund',
                        'campaign_code' => $pledge->campaign?->campaign_id,
                        'currency' => $pledge->currency,
                        'payment_plan' => $pledge->payment_plan_type instanceof \BackedEnum ? $pledge->payment_plan_type->value : (string) $pledge->payment_plan_type,
                        'installment_count' => (int) ($pledge->installment_count ?? count($items)),
                        'installments_paid' => count(array_filter($items, fn (array $i): bool => (float) $i['remaining_amount'] <= self::EPSILON)),
                        'committed' => $this->money((float) $pledge->committed_amount),
                        'committed_ngn' => $this->money($committedNgn),
                        'honored' => $this->money($honored),
                        'honored_ngn' => $this->money($honoredNgn),
                        'pending' => $this->money($pending),
                        'pending_ngn' => $this->money($pendingNgn),
                        'overdue' => $this->money($overdueNative),
                        'overdue_ngn' => $this->money($overdueNgn),
                        'overdue_installments' => count($overdueItems),
                        'earliest_due_date' => $earliest['due_date'],
                        'days_overdue' => $earliest['days_overdue'],
                        'last_payment_at' => $lastPayments[$pledge->uuid] ?? null,
                        'reminders_sent' => is_array($reminders) ? count($reminders) : 0,
                        'is_anonymous' => (bool) $pledge->is_anonymous,
                        'pledged_on' => $pledge->created_at?->toDateString(),
                        'items' => $overdueItems,
                    ];
                    unset($group);
                }
            });

        foreach ($donorGroups as &$group) {
            usort($group['pledges'], fn (array $a, array $b): int => (float) $b['overdue_ngn'] <=> (float) $a['overdue_ngn']);
            foreach (['committed_ngn', 'honored_ngn', 'pending_ngn', 'overdue_ngn'] as $key) {
                $group['totals'][$key.'_numeric'] = $group['totals'][$key];
                $group['totals'][$key] = $this->money($group['totals'][$key]);
            }
        }
        unset($group);

        $donors = array_values($donorGroups);
        usort($donors, fn (array $a, array $b): int => $b['totals']['overdue_ngn_numeric'] <=> $a['totals']['overdue_ngn_numeric']);

        usort($upcoming, fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']) ?: strcmp((string) $a['donor']['name'], (string) $b['donor']['name']));
        usort($paused, fn (array $a, array $b): int => strcmp((string) ($a['resume_date'] ?? '9999'), (string) ($b['resume_date'] ?? '9999')));

        $overdueAmount = array_sum(array_column(array_column($donors, 'totals'), 'overdue_ngn_numeric'));
        $overdueInstallments = array_sum(array_column(array_column($donors, 'totals'), 'overdue_installments'));
        $overduePledges = array_sum(array_column(array_column($donors, 'totals'), 'pledges'));

        $agingRows = [];
        foreach ($aging as $label => $bucket) {
            $agingRows[] = [
                'bucket' => $label,
                'count' => $bucket['count'],
                'amount' => $this->money($bucket['amount']),
                'share' => $overdueAmount > 0 ? round($bucket['amount'] / $overdueAmount * 100, 1) : 0.0,
            ];
        }

        $committed = $activeTotals['committed'];

        return [
            'active_totals' => [
                'count' => $activeTotals['count'],
                'donors' => count($activeTotals['donors']),
                'committed_ngn' => $this->money($committed),
                'honored_ngn' => $this->money($activeTotals['honored']),
                'pending_ngn' => $this->money($activeTotals['pending']),
                'collection_rate' => $committed > 0 ? round($activeTotals['honored'] / $committed * 100, 1) : 0.0,
            ],
            'by_campaign' => $byCampaign,
            'overdue' => [
                'totals' => [
                    'donors' => count($donors),
                    'pledges' => $overduePledges,
                    'installments' => $overdueInstallments,
                    'amount_ngn' => $this->money($overdueAmount),
                    'amount_ngn_numeric' => $overdueAmount,
                    'share_of_pending' => $activeTotals['pending'] > 0 ? round($overdueAmount / $activeTotals['pending'] * 100, 1) : 0.0,
                ],
                'aging' => $agingRows,
                'donors' => $donors,
            ],
            'upcoming' => $upcoming,
            'paused' => $paused,
        ];
    }

    /**
     * @param  array<string, array{min:int, max:int|null, count:int, amount:float}>  $aging
     */
    private function addToAging(array &$aging, int $daysOverdue, float $amount): void
    {
        foreach ($aging as &$bucket) {
            if ($daysOverdue >= $bucket['min'] && ($bucket['max'] === null || $daysOverdue <= $bucket['max'])) {
                $bucket['count']++;
                $bucket['amount'] += $amount;

                return;
            }
        }
    }

    /**
     * @param  list<string>  $pledgeUuids
     * @return array<string, string> pledge uuid => Y-m-d of the latest successful payment
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
                    ->orWhere('application_type', '!=', TransactionApplicationType::PLEDGE_PLACEHOLDER->value);
            })
            ->selectRaw('pledge_uuid, MAX(COALESCE(paid_at, created_at)) as last_paid_at')
            ->groupBy('pledge_uuid')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row->last_paid_at !== null) {
                $out[(string) $row->pledge_uuid] = Carbon::parse((string) $row->last_paid_at)->toDateString();
            }
        }

        return $out;
    }

    private function donorGroupKey(Pledge $pledge): string
    {
        if ($pledge->user_uuid !== null && $pledge->user_uuid !== '') {
            return 'user:'.$pledge->user_uuid;
        }
        $email = strtolower(trim((string) ($pledge->donor_email ?? '')));
        if ($email !== '') {
            return 'email:'.$email;
        }

        return 'pledge:'.$pledge->uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function donorContact(Pledge $pledge): array
    {
        $donor = $pledge->donor;
        $name = trim((string) ($pledge->donor_name ?? ''));
        if ($name === '' && $donor !== null) {
            $name = $donor->displayName();
        }

        $email = trim((string) ($pledge->donor_email ?? $donor?->email ?? ''));
        $phone = trim((string) ($pledge->donor_phone ?? $donor?->phone_number ?? ''));

        return [
            'key' => $this->donorGroupKey($pledge),
            'user_uuid' => $pledge->user_uuid,
            'name' => $name !== '' ? $name : ($email !== '' ? $email : 'Unknown donor'),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'donor_type' => $pledge->donorType?->label ?? $donor?->donorType?->label,
            'graduation_set' => $pledge->graduationSet?->name ?? $donor?->graduationSet?->name,
            'is_registered' => $donor !== null,
            'is_anonymous' => (bool) $pledge->is_anonymous,
        ];
    }

    /**
     * @return array{count:int, committed_ngn:string}
     */
    private function newPledgeTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        $query = Pledge::query()->whereBetween('created_at', [$from, $to]);

        return [
            'count' => (int) (clone $query)->count(),
            'committed_ngn' => $this->money((float) (clone $query)->sum('committed_amount_ngn')),
        ];
    }

    private function fulfilledPledgeCount(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Pledge::query()
            ->where('status', PledgeStatus::FULFILLED)
            ->whereBetween('fulfilled_at', [$from, $to])
            ->count();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function newPledgeRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return Pledge::query()
            ->whereBetween('created_at', [$from, $to])
            ->with(['campaign:uuid,name', 'donor:uuid,firstname,lastname,organization_name,email,phone_number'])
            ->orderByDesc('committed_amount_ngn')
            ->get()
            ->map(fn (Pledge $pledge): array => [
                'pledge_uuid' => $pledge->uuid,
                'donor' => $this->donorContact($pledge),
                'campaign' => $pledge->campaign?->name,
                'committed' => $this->money((float) $pledge->committed_amount),
                'currency' => $pledge->currency,
                'committed_ngn' => $this->money((float) ($pledge->committed_amount_ngn ?? 0)),
                'payment_plan' => $pledge->payment_plan_type instanceof \BackedEnum ? $pledge->payment_plan_type->value : (string) $pledge->payment_plan_type,
                'installment_count' => $pledge->installment_count,
                'status' => $pledge->status instanceof \BackedEnum ? $pledge->status->value : (string) $pledge->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fulfilledPledgeRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return Pledge::query()
            ->where('status', PledgeStatus::FULFILLED)
            ->whereBetween('fulfilled_at', [$from, $to])
            ->with(['campaign:uuid,name', 'donor:uuid,firstname,lastname,organization_name,email,phone_number'])
            ->orderByDesc('committed_amount_ngn')
            ->get()
            ->map(fn (Pledge $pledge): array => [
                'pledge_uuid' => $pledge->uuid,
                'donor' => $this->donorContact($pledge),
                'campaign' => $pledge->campaign?->name,
                'committed' => $this->money((float) $pledge->committed_amount),
                'currency' => $pledge->currency,
                'committed_ngn' => $this->money((float) ($pledge->committed_amount_ngn ?? 0)),
                'pledged_on' => $pledge->created_at?->toDateString(),
                'fulfilled_at' => $pledge->fulfilled_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{count:int, amount_ngn:string, oldest_at:string|null}
     */
    private function awaitingBankVerification(CarbonInterface $asOf): array
    {
        $query = Transaction::query()
            ->where('status', TransactionStatus::PENDING)
            ->whereNotNull('awaiting_bank_verification_at')
            ->whereNull('reconciled_at')
            ->where('awaiting_bank_verification_at', '<=', $asOf);

        $oldest = (clone $query)->min('awaiting_bank_verification_at');

        return [
            'count' => (int) (clone $query)->count(),
            'amount_ngn' => $this->money((float) (clone $query)->sum('amount_in_naira')),
            'oldest_at' => $oldest !== null ? Carbon::parse((string) $oldest)->toDateString() : null,
        ];
    }

    // ---------------------------------------------------------------------
    // Campaigns
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, array<string, float|int>>  $pledgesByCampaign
     * @return list<array<string,mixed>>
     */
    private function campaignRows(CarbonInterface $reportDate, array $pledgesByCampaign): array
    {
        $campaigns = Campaign::query()
            ->where(function (Builder $b) use ($pledgesByCampaign): void {
                $b->where('status', CampaignStatus::ACTIVE);
                if ($pledgesByCampaign !== []) {
                    $b->orWhereIn('uuid', array_keys($pledgesByCampaign));
                }
            })
            ->orderBy('name')
            ->get(['uuid', 'campaign_id', 'name', 'target_amount', 'base_currency', 'status', 'end_date']);

        if ($campaigns->isEmpty()) {
            return [];
        }

        $uuids = $campaigns->pluck('uuid')->all();
        $raisedRows = $this->revenueQuery(null, $reportDate->copy()->endOfDay())
            ->whereIn('campaign_uuid', $uuids)
            ->selectRaw('campaign_uuid, COUNT(*) as tx_count, SUM(amount_in_naira) as total')
            ->groupBy('campaign_uuid')
            ->get()
            ->keyBy('campaign_uuid');
        $yesterdayRows = $this->revenueQuery($reportDate, $reportDate->copy()->endOfDay())
            ->whereIn('campaign_uuid', $uuids)
            ->selectRaw('campaign_uuid, COUNT(*) as tx_count, SUM(amount_in_naira) as total')
            ->groupBy('campaign_uuid')
            ->get()
            ->keyBy('campaign_uuid');
        $mtdRows = $this->revenueQuery($reportDate->copy()->startOfMonth(), $reportDate->copy()->endOfDay())
            ->whereIn('campaign_uuid', $uuids)
            ->selectRaw('campaign_uuid, COUNT(*) as tx_count, SUM(amount_in_naira) as total')
            ->groupBy('campaign_uuid')
            ->get()
            ->keyBy('campaign_uuid');

        $rows = [];
        foreach ($campaigns as $campaign) {
            $raised = (float) ($raisedRows[$campaign->uuid]->total ?? 0);
            $target = (float) $campaign->target_amount;
            $pledged = $pledgesByCampaign[$campaign->uuid] ?? [
                'pledges' => 0, 'committed' => 0.0, 'honored' => 0.0, 'pending' => 0.0,
                'scheduled_to_date' => 0.0, 'overdue' => 0.0, 'overdue_pledges' => 0, 'paused' => 0,
            ];
            $scheduled = (float) $pledged['scheduled_to_date'];
            $honored = (float) $pledged['honored'];

            $rows[] = [
                'uuid' => $campaign->uuid,
                'code' => $campaign->campaign_id,
                'name' => $campaign->name,
                'status' => $campaign->status instanceof \BackedEnum ? $campaign->status->value : (string) $campaign->status,
                'base_currency' => $campaign->base_currency,
                'end_date' => $campaign->end_date?->toDateString(),
                'target_ngn' => $this->money($target),
                'raised_ngn' => $this->money($raised),
                'raised_numeric' => $raised,
                'progress_percent' => $target > 0 ? round($raised / $target * 100, 1) : null,
                'remaining_to_target_ngn' => $target > 0 ? $this->money(max(0, $target - $raised)) : null,
                'donations_count' => (int) ($raisedRows[$campaign->uuid]->tx_count ?? 0),
                'yesterday_count' => (int) ($yesterdayRows[$campaign->uuid]->tx_count ?? 0),
                'yesterday_ngn' => $this->money((float) ($yesterdayRows[$campaign->uuid]->total ?? 0)),
                'mtd_count' => (int) ($mtdRows[$campaign->uuid]->tx_count ?? 0),
                'mtd_ngn' => $this->money((float) ($mtdRows[$campaign->uuid]->total ?? 0)),
                'active_pledges' => (int) $pledged['pledges'],
                'paused_pledges' => (int) $pledged['paused'],
                'pledged_committed_ngn' => $this->money((float) $pledged['committed']),
                'pledged_honored_ngn' => $this->money($honored),
                'pledged_pending_ngn' => $this->money((float) $pledged['pending']),
                'scheduled_to_date_ngn' => $this->money($scheduled),
                'schedule_gap_ngn' => $this->money(max(0, $scheduled - $honored)),
                'schedule_variance_ngn' => $this->money($honored - $scheduled),
                'collection_rate' => $scheduled > 0 ? round(min($honored, $scheduled) / $scheduled * 100, 1) : null,
                'overdue_ngn' => $this->money((float) $pledged['overdue']),
                'overdue_pledges' => (int) $pledged['overdue_pledges'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['raised_numeric'] <=> $a['raised_numeric']);

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  list<float|int>  $values
     */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * @param  list<array<string,mixed>>  $daily
     * @return array<string,mixed>|null
     */
    private function peak(array $daily): ?array
    {
        $peak = null;
        foreach ($daily as $day) {
            if ($peak === null || $day['amount_numeric'] > $peak['amount_numeric']) {
                $peak = $day;
            }
        }

        return $peak !== null && $peak['amount_numeric'] > 0 ? $peak : null;
    }

    private function changePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function changeDirection(float $current, float $previous): string
    {
        return match (true) {
            $current > $previous => 'increase',
            $current < $previous => 'decrease',
            default => 'no_change',
        };
    }

    private function dateOnly(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
