<?php

namespace App\Services\Customer;

use App\Enums\Currency;
use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerDonationDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(User $user, ?string $campaignUuid = null): array
    {
        $txQuery = Transaction::query()->countableTowardRevenue()->where('user_uuid', $user->uuid);
        if ($campaignUuid !== null && $campaignUuid !== '') {
            $txQuery->where('campaign_uuid', $campaignUuid);
        }

        $totalDonated = (string) ($txQuery->clone()->sum('amount_in_naira') ?: '0');
        $donationCount = (int) $txQuery->clone()->count();

        $pledgeQuery = Pledge::query()->where('user_uuid', $user->uuid)->where('status', '!=', PledgeStatus::CANCELLED);
        if ($campaignUuid !== null && $campaignUuid !== '') {
            $pledgeQuery->where('campaign_uuid', $campaignUuid);
        }

        $ngnCode = Currency::NGN->value;
        $pledgesCommittedNgn = (string) (($pledgeQuery->clone()
            ->selectRaw(
                'SUM(COALESCE(committed_amount_ngn, CASE WHEN UPPER(TRIM(currency)) = ? THEN committed_amount ELSE 0 END)) as s',
                [$ngnCode]
            )
            ->value('s')) ?? '0');

        $pledgesByCurrency = $pledgeQuery->clone()
            ->selectRaw(
                'currency, SUM(committed_amount) as total_committed, SUM(COALESCE(committed_amount_ngn, CASE WHEN UPPER(TRIM(currency)) = ? THEN committed_amount ELSE 0 END)) as total_committed_ngn',
                [$ngnCode]
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

        return [
            'total_donations_amount_ngn' => $totalDonated,
            'donation_count' => $donationCount,
            'pledges_committed_amount_ngn' => $pledgesCommittedNgn,
            'pledges_committed_by_currency' => $pledgesByCurrency,
            'pledges_committed_amount' => $pledgesCommittedNgn,
            'currency_preview' => Currency::NGN->value,
        ];
    }

    /**
     * Paginated transactions for the given donor account only (never cross-user).
     *
     * @param  array{per_page?: int, campaign_uuid?: string|null, filters?: array{scope?: string}}  $filters
     */
    public function transactionHistory(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        $q = Transaction::query()
            ->where('user_uuid', $user->uuid)
            ->whereNotNull('user_uuid')
            ->where('status', '!=', TransactionStatus::SUPERSEDED)
            ->with(['campaign:uuid,name', 'pledge:uuid,committed_amount,currency,committed_amount_ngn'])
            ->orderByDesc('created_at');

        $scope = strtolower(trim((string) (data_get($filters, 'filters.scope') ?? 'all')));
        if (! in_array($scope, ['all', 'pledges', 'donations'], true)) {
            $scope = 'all';
        }
        if ($scope === 'pledges') {
            // Payments recorded against a pledge (Pledge rows themselves live on GET /me/pledges).
            $pledgeUuids = Pledge::query()
                ->where('user_uuid', $user->uuid)
                ->pluck('uuid');
            $q->whereIn('pledge_uuid', $pledgeUuids);
        } elseif ($scope === 'donations') {
            $q->whereNull('pledge_uuid');
        }

        if (! empty($filters['campaign_uuid'])) {
            $q->where('campaign_uuid', (string) $filters['campaign_uuid']);
        }

        return $q->paginate($perPage);
    }
}
