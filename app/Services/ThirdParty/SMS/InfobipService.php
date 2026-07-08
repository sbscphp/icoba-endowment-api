<?php

namespace App\Services\ThirdParty\SMS;

use App\Services\Curl\CurlService;
use Illuminate\Support\Facades\Log;

class InfobipService implements SmsProviderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetchBalance(): ?array
    {
        $baseUrl = rtrim((string) config('services.sms.infobip.base_url'), '/');
        $apiKey = (string) config('services.sms.infobip.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        $url = $baseUrl.'/account/1/balance';

        $headers = [
            'Authorization: '.$apiKey,
            'Accept: application/json',
        ];

        try {
            $response = CurlService::getRequest($url, $headers);

            if (! is_array($response) || ! isset($response['balance'])) {
                Log::warning('Infobip balance check returned unexpected response', ['response' => $response]);

                return null;
            }

            return [
                'balance' => (float) $response['balance'],
                'currency' => (string) ($response['currency'] ?? config('sms-usage.infobip.currency', 'USD')),
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            Log::error('Infobip balance check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function send(string $to, string $message): array
    {
        $baseUrl = rtrim((string) config('services.sms.infobip.base_url'), '/');
        $apiKey = (string) config('services.sms.infobip.api_key');
        $sender = (string) config('services.sms.infobip.sender', config('app.name', 'Laravel'));

        if ($baseUrl === '' || $apiKey === '') {
            return ['sent' => false, 'provider' => 'infobip', 'reason' => 'infobip_not_configured'];
        }

        $url = $baseUrl.'/sms/2/text/advanced';

        $headers = [
            'Authorization: '.$apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $payload = [
            'messages' => [[
                'from' => $sender,
                'destinations' => [['to' => $to]],
                'text' => $message,
            ]],
        ];

        try {
            $response = CurlService::postRequest($url, $payload, $headers);

            Log::info('Infobip SMS response', ['response' => $response]);

            return [
                'sent' => true,
                'provider' => 'infobip',
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            Log::error('Infobip SMS failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'provider' => 'infobip',
                'error' => $e->getMessage(),
            ];
        }
    }
}
