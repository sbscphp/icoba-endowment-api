<?php

namespace App\Services\ThirdParty\SMS;

use App\Services\Curl\CurlService;

class InfobipService implements SmsProviderInterface
{
    public function send(string $to, string $message): array
    {
        $baseUrl = rtrim((string) config('services.sms.infobip.base_url'), '/');
        $apiKey = (string) config('services.sms.infobip.api_key');
        $sender = (string) config('services.sms.infobip.sender', config('app.name', 'Laravel'));

        if ($baseUrl === '' || $apiKey === '') {
            return ['sent' => false, 'reason' => 'infobip_not_configured'];
        }

        $response = CurlService::postRequest($baseUrl.'/sms/2/text/advanced', [
            'messages' => [[
                'from' => $sender,
                'destinations' => [['to' => $to]],
                'text' => $message,
            ]],
        ], [
            'Authorization: '.$apiKey,
            'Accept: application/json',
        ]);

        return ['sent' => true, 'provider' => 'infobip', 'response' => $response];
    }
}
