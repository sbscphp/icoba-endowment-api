<?php

namespace App\Services\Sms;

use App\Services\Curl\CurlService;
use Illuminate\Support\Facades\Log;

class InfobipSMS
{
    public function __construct(private readonly CurlService $curl) {}

    public function send(string $to, string $message, ?string $from = null): array
    {
        $baseUrl = rtrim(config('quiva.infobip_base_url'), '/');
        $auth    = config('quiva.infobip_secret_key');
        $from    = $from ?? config('quiva.infobip_sender', 'Quiva');

        $url = $baseUrl . '/sms/2/text/advanced';

        $headers = [
            'Authorization: ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = [
            'messages' => [
                [
                    'from' => $from,
                    'destinations' => [
                        ['to' => $to],
                    ],
                    'text' => $message,
                ],
            ],
        ];

        try {
            return $this->curl->postRequest($url, $payload, $headers);
        } catch (\Throwable $e) {
            Log::error('Infobip SMS failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
