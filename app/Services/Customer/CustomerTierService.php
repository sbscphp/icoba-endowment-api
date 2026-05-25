<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Services\Tier\TierResolutionService;

class CustomerTierService
{
    public function __construct(
        private readonly CustomerDonationDashboardService $donationDashboard,
        private readonly TierResolutionService $tierResolution,
    ) {}

    /**
     * @return array{donation_tier: array<string, mixed>, pledge_tier: array<string, mixed>}
     */
    public function tiersForUser(User $user): array
    {
        $summary = $this->donationDashboard->dashboardSummary($user);
        $donationNgn = (float) ($summary['total_donations_amount_ngn'] ?? 0);
        $pledgeNgn = (float) ($summary['pledges_committed_amount_ngn'] ?? 0);

        return [
            'donation_tier' => $this->tierResolution->resolveSummaryForAmount($donationNgn),
            'pledge_tier' => $this->tierResolution->resolveSummaryForAmount($pledgeNgn),
        ];
    }
}
