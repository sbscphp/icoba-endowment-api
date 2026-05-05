<?php

namespace App\Services\ThirdParty\SMS;

use App\Services\Curl\CurlService;

class TermiiService implements SmsProviderInterface
{
    public function send(string $to, string $message): array
    {
        $baseUrl = rtrim((string) config('services.sms.termii.base_url'), '/');
        $apiKey = (string) config('services.sms.termii.api_key');
        $sender = (string) config('services.sms.termii.sender', config('app.name', 'Laravel'));

        if ($baseUrl === '' || $apiKey === '') {
            return ['sent' => false, 'reason' => 'termii_not_configured'];
        }

        $response = CurlService::postRequest($baseUrl.'/api/sms/send', [
            'api_key' => $apiKey,
            'from' => $sender,
            'to' => $to,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'dnd',
        ]);

        return ['sent' => true, 'provider' => 'termii', 'response' => $response];
    }
}
