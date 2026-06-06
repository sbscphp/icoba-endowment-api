<?php

namespace App\Services\ThirdParty\SMS;

use App\Services\Curl\CurlService;
use Illuminate\Support\Facades\Log;

class TermiiService implements SmsProviderInterface
{
    public function __construct(
        private readonly SmsBalanceAlertService $balanceAlertService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetchBalance(): ?array
    {
        $baseUrl = rtrim((string) config('services.sms.termii.base_url'), '/');
        $apiKey = (string) config('services.sms.termii.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        $url = $baseUrl.'/api/get-balance?api_key='.rawurlencode($apiKey);

        try {
            $response = CurlService::getRequest($url);

            if (! is_array($response) || ! isset($response['balance'])) {
                Log::warning('Termii balance check returned unexpected response', ['response' => $response]);

                return null;
            }

            return [
                'balance' => (float) $response['balance'],
                'currency' => (string) ($response['currency'] ?? 'NGN'),
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            Log::error('Termii balance check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

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
                        $this->balanceAlertService->notifyIfBudgetThresholdCrossed(
                            'termii',
                            (float) $response['balance'],
                            $response
                        );
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
