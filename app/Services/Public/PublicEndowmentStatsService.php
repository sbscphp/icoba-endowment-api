<?php

namespace App\Services\Public;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;

class PublicEndowmentStatsService
{
    private const CACHE_KEY = 'public.endowment.stats';

    /**
     * @return array<string, int|string|float>
     */
    public function stats(): array
    {
        $ttlSeconds = max(0, (int) config('endowment.public_stats_cache_seconds', 300));

        if ($ttlSeconds === 0) {
            return $this->buildStats();
        }

        return Cache::remember(self::CACHE_KEY, now()->addSeconds($ttlSeconds), fn (): array => $this->buildStats());
    }

    /**
     * @return array<string, int|string|float>
     */
    private function buildStats(): array
    {
        $projectGoalNaira = (float) config('endowment.project_goal_naira', 10_000_000_000);
        $donationMetrics = $this->donationMetrics();
        $totalDonationsNaira = (float) ($donationMetrics->total_donations_naira ?? 0);
        $uniqueDonorsCount = (int) ($donationMetrics->user_donors_count ?? 0)
            + (int) ($donationMetrics->email_donors_count ?? 0)
            + (int) ($donationMetrics->anonymous_donors_count ?? 0);

        $progressPercent = $projectGoalNaira > 0
            ? round(min(100, ($totalDonationsNaira / $projectGoalNaira) * 100), 2)
            : 0.0;

        return [
            'project_goal_naira' => (string) $projectGoalNaira,
            'total_donations_naira' => (string) $totalDonationsNaira,
            'unique_donors_count' => $uniqueDonorsCount,
            'campaigns_count' => $this->campaignsCount(),
            'progress_percent' => $progressPercent,
        ];
    }

    private function donationMetrics(): object
    {
        return Transaction::query()
            ->countableTowardRevenue()
            ->selectRaw('COALESCE(SUM(amount_in_naira), 0) as total_donations_naira')
            ->selectRaw('COUNT(DISTINCT user_uuid) as user_donors_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN user_uuid IS NULL AND donor_email IS NOT NULL AND donor_email != '' THEN donor_email END) as email_donors_count")
            ->selectRaw("SUM(CASE WHEN user_uuid IS NULL AND (donor_email IS NULL OR donor_email = '') THEN 1 ELSE 0 END) as anonymous_donors_count")
            ->toBase()
            ->first() ?? (object) [
                'total_donations_naira' => 0,
                'user_donors_count' => 0,
                'email_donors_count' => 0,
                'anonymous_donors_count' => 0,
            ];
    }

    private function campaignsCount(): int
    {
        return (int) Campaign::query()
            ->where('is_default', false)
            ->whereIn('status', [CampaignStatus::ACTIVE, CampaignStatus::COMPLETED])
            ->count();
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
