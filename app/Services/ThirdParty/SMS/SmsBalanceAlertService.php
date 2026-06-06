<?php

namespace App\Services\ThirdParty\SMS;

use App\Mail\SmsBalanceLowEmail;
use App\Services\Theme\ThemeResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SmsBalanceAlertService
{
    private const CACHE_KEY_PREFIX = 'sms_balance:last_budget_percentage_used:';

    /**
     * Email when budget usage crosses a warn_at threshold.
     *
     * Scheduled checks only notify the first time each threshold is crossed.
     * Manual command runs notify whenever usage is still at or above a threshold.
     *
     * percentageUsed = (monthly_budget - balance) / monthly_budget * 100
     *
     * @param  array<string, mixed>  $response
     * @return array{notified: bool, percentage_used: ?float, threshold_percent: ?float, reason: ?string}
     */
    public function notifyIfBudgetThresholdCrossed(string $provider, float $balance, array $response = [], bool $forceNotify = false): array
    {
        $config = config("sms-usage.{$provider}");
        if (! is_array($config)) {
            return ['notified' => false, 'percentage_used' => null, 'threshold_percent' => null, 'reason' => 'provider_not_configured'];
        }

        $monthlyBudget = (float) ($config['monthly_budget'] ?? 0);
        if ($monthlyBudget <= 0) {
            return ['notified' => false, 'percentage_used' => null, 'threshold_percent' => null, 'reason' => 'monthly_budget_not_set'];
        }

        $percentageUsed = (($monthlyBudget - $balance) / $monthlyBudget) * 100;

        $thresholds = $config['warn_at'] ?? [];
        if (! is_array($thresholds) || $thresholds === []) {
            Cache::forever($this->cacheKey($provider), $percentageUsed);

            return ['notified' => false, 'percentage_used' => round($percentageUsed, 2), 'threshold_percent' => null, 'reason' => 'no_warn_thresholds'];
        }

        $thresholds = array_map('floatval', $thresholds);
        sort($thresholds, SORT_NUMERIC);

        $previous = Cache::get($this->cacheKey($provider));
        $previousPct = $previous !== null ? (float) $previous : null;

        $thresholdToNotify = null;

        if ($forceNotify) {
            $metThresholds = array_values(array_filter(
                $thresholds,
                static fn (float $threshold): bool => $percentageUsed >= $threshold
            ));

            if ($metThresholds !== []) {
                $thresholdToNotify = max($metThresholds);
            }
        } else {
            $newlyCrossed = [];
            foreach ($thresholds as $threshold) {
                if ($percentageUsed >= $threshold && ($previousPct === null || $previousPct < $threshold)) {
                    $newlyCrossed[] = $threshold;
                }
            }

            if ($newlyCrossed !== []) {
                $thresholdToNotify = max($newlyCrossed);
            }
        }

        $notified = false;

        if ($thresholdToNotify !== null) {
            $addresses = $config['notify']['addresses'] ?? [];
            if ($addresses === []) {
                Cache::forever($this->cacheKey($provider), $percentageUsed);

                return [
                    'notified' => false,
                    'percentage_used' => round($percentageUsed, 2),
                    'threshold_percent' => $thresholdToNotify,
                    'reason' => 'no_alert_emails_configured',
                ];
            }

            $currency = (string) ($config['currency'] ?? ($response['currency'] ?? ''));
            $payload = array_merge($response, [
                'provider' => $provider,
                'balance' => $balance,
                'currency' => $currency,
                'percentage_used' => round($percentageUsed, 2),
                'threshold_percent' => $thresholdToNotify,
                'monthly_budget' => $monthlyBudget,
            ]);

            try {
                $theme = app(ThemeResolver::class)->resolveForMail();
                Mail::to($addresses)->send(new SmsBalanceLowEmail($payload, $theme));
                $notified = true;
            } catch (\Throwable $e) {
                Log::error('SMS budget threshold alert email failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);

                Cache::forever($this->cacheKey($provider), $percentageUsed);

                return [
                    'notified' => false,
                    'percentage_used' => round($percentageUsed, 2),
                    'threshold_percent' => $thresholdToNotify,
                    'reason' => 'email_send_failed',
                ];
            }
        }

        Cache::forever($this->cacheKey($provider), $percentageUsed);

        return [
            'notified' => $notified,
            'percentage_used' => round($percentageUsed, 2),
            'threshold_percent' => $thresholdToNotify,
            'reason' => $notified ? null : 'threshold_not_crossed',
        ];
    }

    private function cacheKey(string $provider): string
    {
        return self::CACHE_KEY_PREFIX.$provider;
    }
}
