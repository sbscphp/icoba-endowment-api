<?php

namespace App\Services\ThirdParty\SMS;

use App\Mail\TermiiBalanceLowEmail;
use App\Services\Curl\CurlService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TermiiService implements SmsProviderInterface
{
    /** Last budget usage % (same formula as termii:check-balance) for crossing detection. */
    private const CACHE_LAST_BUDGET_PERCENTAGE_USED = 'termii_sms:last_budget_percentage_used';

    /**
     * @return array<string, mixed>
     */
    public function send(string $to, string $message): array
    {
        $baseUrl = rtrim((string) config('services.sms.termii.base_url'), '/');
        $apiKey = (string) config('services.sms.termii.api_key');
        $sender = (string) config('services.sms.termii.sender', config('app.name', 'ICOBA'));

        if ($baseUrl === '' || $apiKey === '') {
            return ['sent' => false, 'provider' => 'termii', 'reason' => 'termii_not_configured'];
        }

        $url = $baseUrl.'/api/sms/send';

        $headers = [
            'Content-Type: application/json',
        ];

        $payload = [
            'api_key' => $apiKey,
            'from' => $sender,
            'to' => $to,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'dnd',
        ];

        return $this->sendRequest($url, $payload, $headers);
    }

    /**
     * Send a request to the Termii API and handle the response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $headers
     * @return array<string, mixed>
     */
    private function sendRequest(string $url, array $data, array $headers): array
    {
        try {
            $response = CurlService::postRequest($url, $data, $headers);

            Log::info('Main response', ['response' => $response]);

            if (isset($response['code'])) {
                $code = $response['code'];

                if ($code == 'ok') {
                    if (isset($response['balance'])) {
                        $this->notifyIfBudgetThresholdCrossed((float) $response['balance'], $response);
                    }

                    return [
                        'sent' => true,
                        'provider' => 'termii',
                        'response' => $response,
                    ];
                }

                $errorMessage = $this->getErrorMessage($code);
                Log::error('Termii API request failed', ['code' => $code, 'response' => $response]);

                return [
                    'sent' => false,
                    'provider' => 'termii',
                    'error' => $errorMessage,
                    'code' => $code,
                    'response' => $response,
                ];
            }

            Log::error('Termii API request failed: Unexpected response format', ['response' => $response]);

            return [
                'sent' => false,
                'provider' => 'termii',
                'error' => 'Unexpected response format from Termii API.',
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            Log::error('Termii API request failed: Exception', ['error' => $e->getMessage()]);

            return [
                'sent' => false,
                'provider' => 'termii',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Email when budget usage (see config termii-usage) first crosses a warn_at threshold — not on every SMS.
     * Matches CheckTermiiBalanceCommand: percentageUsed = (monthly_budget_ngn - balance) / monthly_budget_ngn * 100.
     *
     * @param  array<string, mixed>  $response
     */
    private function notifyIfBudgetThresholdCrossed(float $balance, array $response): void
    {
        $config = config('termii-usage');
        $monthlyBudget = (float) ($config['monthly_budget_ngn'] ?? 0);

        if ($monthlyBudget <= 0) {
            return;
        }

        $percentageUsed = (($monthlyBudget - $balance) / $monthlyBudget) * 100;

        $thresholds = $config['warn_at'] ?? [];
        if (! is_array($thresholds) || $thresholds === []) {
            Cache::forever(self::CACHE_LAST_BUDGET_PERCENTAGE_USED, $percentageUsed);

            return;
        }

        $thresholds = array_map('floatval', $thresholds);
        sort($thresholds, SORT_NUMERIC);

        $previous = Cache::get(self::CACHE_LAST_BUDGET_PERCENTAGE_USED);
        $previousPct = $previous !== null ? (float) $previous : null;

        $newlyCrossed = [];
        foreach ($thresholds as $threshold) {
            if ($percentageUsed >= $threshold && ($previousPct === null || $previousPct < $threshold)) {
                $newlyCrossed[] = $threshold;
            }
        }

        if ($newlyCrossed !== []) {
            $highestCrossed = max($newlyCrossed);
            $addresses = $config['notify']['addresses'] ?? [];
            if ($addresses !== []) {
                $payload = array_merge($response, [
                    'percentage_used' => round($percentageUsed, 2),
                    'threshold_percent' => $highestCrossed,
                    'monthly_budget_ngn' => $monthlyBudget,
                ]);
                try {
                    $theme = app(ThemeResolver::class)->resolveForMail();
                    Mail::to($addresses)->send(new TermiiBalanceLowEmail($payload, $theme));
                } catch (\Throwable $e) {
                    Log::error('Termii budget threshold alert email failed', ['error' => $e->getMessage()]);
                }
            }
        }

        Cache::forever(self::CACHE_LAST_BUDGET_PERCENTAGE_USED, $percentageUsed);
    }

    /**
     * Map Termii error codes to human-readable error messages.
     */
    private function getErrorMessage(string $code): string
    {
        return match ($code) {
            'bad_request' => 'Invalid request. Please check the input data.',
            'unauthorized' => 'Authentication failed. Please check your API key.',
            'forbidden' => 'You do not have permission to perform this action.',
            'not_found' => 'The requested resource was not found.',
            'method_not_allowed' => 'The selected HTTP method is not allowed.',
            'unprocessable_entity' => 'The request was understood but could not be processed.',
            'too_many_requests' => 'You have sent too many requests. Please try again later.',
            default => 'Failed to send message due to a server error. Please try again later.',
        };
    }
}
