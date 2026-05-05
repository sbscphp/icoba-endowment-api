<?php

namespace App\Services\Sms;

use App\Mail\TermiiBalanceLowEmail;
use App\Services\Curl\CurlService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TermiiSMSService
{
    /** Last budget usage % (same formula as termii:check-balance) for crossing detection. */
    private const CACHE_LAST_BUDGET_PERCENTAGE_USED = 'termii_sms:last_budget_percentage_used';

    /**
     * Send a single SMS message.
     */
    public static function sendMessage($request)
    {
        $baseUrl = env('TERMII_BASE_URL');
        $apiKey = env('TERMII_API_KEY');

        $url = $baseUrl.'/api/sms/send';

        $headerParams = [
            'Content-Type: application/json',
        ];

        $data = [
            'api_key' => $apiKey,
            'from' => env('APP_NAME'),
            'to' => $request['phone_number'],
            'sms' => $request['message'],
            'type' => 'plain',
            'channel' => 'dnd', // "generic",
        ];

        return self::sendRequest($url, $data, $headerParams);
    }

    /**
     * Send an OTP notification via SMS.
     */
    public static function sendOtpNotification($phoneNumber, $otp, $otpMins)
    {
        $baseUrl = env('TERMII_BASE_URL');
        $apiKey = env('TERMII_API_KEY');

        $message = 'Your '.env('APP_NAME')." Verification PIN is: {$otp}. It expires in {$otpMins} minutes. ".env('APP_NAME');

        $url = $baseUrl.'/api/sms/send';

        $headerParams = [
            'Content-Type: application/json',
        ];

        $data = [
            'api_key' => $apiKey,
            'from' => env('APP_NAME'),
            // 'from' => '', //env('APP_NAME'), //promotional
            'to' => $phoneNumber,
            'sms' => $message,
            'type' => 'plain',
            // 'channel' => "generic",
            'channel' => 'dnd',
        ];

        return self::sendRequest($url, $data, $headerParams);
    }

    /**
     * Send a bulk SMS message.
     */
    public static function sendBulkMessage($phoneNumbers, $message)
    {
        $baseUrl = env('TERMII_BASE_URL');
        $apiKey = env('TERMII_API_KEY');
        $message = 'Welcome to '.env('APP_NAME').' platform';

        $url = $baseUrl.'/api/sms/send/bulk';

        $headerParams = [
            'Content-Type: application/json',
        ];

        $data = [
            'api_key' => $apiKey,
            'from' => env('APP_NAME'),
            'to' => $phoneNumbers,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic',
        ];

        return self::sendRequest($url, $data, $headerParams);
    }

    /**
     * Send a request to the Termii API and handle the response.
     */
    private static function sendRequest($url, $data, $headerParams)
    {
        try {
            $response = CurlService::postRequest($url, $data, $headerParams);

            Log::info('Main response', ['response' => $response]);

            // Check if the response contains a 'code' field
            if (isset($response['code'])) {
                $code = $response['code'];

                // Handle success (code === 'ok')
                if ($code == 'ok') {
                    if (isset($response['balance'])) {
                        self::notifyIfBudgetThresholdCrossed((float) $response['balance'], $response);
                    }

                    return [
                        'success' => true,
                        'data' => $response,
                    ];
                }

                // Handle specific errors based on the 'code' field
                $errorMessage = self::getErrorMessage($code);
                Log::error('Termii API request failed', ['code' => $code, 'response' => $response]);

                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'code' => $code,
                ];
            }

            // Handle unexpected response format (no 'code' field)
            Log::error('Termii API request failed: Unexpected response format', ['response' => $response]);

            return [
                'success' => false,
                'error' => 'Unexpected response format from Termii API.',
            ];
        } catch (\Exception $e) {
            // Handle exceptions (e.g., network errors)
            Log::error('Termii API request failed: Exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Email when budget usage (see config termii-usage) first crosses a warn_at threshold — not on every SMS.
     * Matches CheckTermiiBalanceCommand: percentageUsed = (monthly_budget_ngn - balance) / monthly_budget_ngn * 100.
     */
    private static function notifyIfBudgetThresholdCrossed(float $balance, array $response): void
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
                    Mail::to($addresses)->send(new TermiiBalanceLowEmail($payload));
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
    private static function getErrorMessage($code)
    {
        switch ($code) {
            case 'bad_request':
                return 'Invalid request. Please check the input data.';
            case 'unauthorized':
                return 'Authentication failed. Please check your API key.';
            case 'forbidden':
                return 'You do not have permission to perform this action.';
            case 'not_found':
                return 'The requested resource was not found.';
            case 'method_not_allowed':
                return 'The selected HTTP method is not allowed.';
            case 'unprocessable_entity':
                return 'The request was understood but could not be processed.';
            case 'too_many_requests':
                return 'You have sent too many requests. Please try again later.';
            default:
                return 'Failed to send message due to a server error. Please try again later.';
        }
    }
}

// Sending a single message
// $request = [
//     'phonenumber' => '90126727', // without the '234' prefix
//     'message' => 'Hello, this is a test message.'
// ];
// $response = TermiiSMSService::sendMessage($request);

// // Sending an OTP
// $response = TermiiSMSService::sendOtpNotification('23490126727', '123456', 5);

// // Sending a bulk message
// $phoneNumbers = ["23490126727", "23490126728"];
// $message = "Hello, this is a bulk test message.";
// $response = TermiiSMSService::sendBulkMessage($phoneNumbers, $message);

// if ($response['success']) {
//     return response()->json([
//         'message' => 'OTP sent successfully.',
//         'data' => $response['data'],
//     ]);
// } else {
//     return response()->json([
//         'message' => 'Failed to send OTP.',
//         'error' => $response['error'],
//     ], 400);
// }
