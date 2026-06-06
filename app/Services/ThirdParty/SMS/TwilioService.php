<?php

namespace App\Services\ThirdParty\SMS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwilioService implements SmsProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function send(string $to, string $message): array
    {
        $accountSid = (string) config('services.sms.twilio.account_sid');
        $authToken = (string) config('services.sms.twilio.auth_token');
        $from = (string) config('services.sms.twilio.from', config('app.name', 'Laravel'));

        if ($accountSid === '' || $authToken === '' || $from === '') {
            return ['sent' => false, 'provider' => 'twilio', 'reason' => 'twilio_not_configured'];
        }

        $url = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            rawurlencode($accountSid)
        );

        $toE164 = str_starts_with($to, '+') ? $to : '+'.$to;

        try {
            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->timeout(max(1, (int) config('services.sms.twilio.timeout', 120)))
                ->post($url, [
                    'To' => $toE164,
                    'From' => $from,
                    'Body' => $message,
                ]);

            $body = $response->json() ?? [];

            Log::info('Twilio SMS response', ['response' => $body, 'status' => $response->status()]);

            if ($response->successful() && isset($body['sid'])) {
                return [
                    'sent' => true,
                    'provider' => 'twilio',
                    'response' => $body,
                ];
            }

            $errorMessage = is_string($body['message'] ?? null)
                ? $body['message']
                : 'Failed to send message via Twilio.';

            Log::error('Twilio SMS failed', [
                'status' => $response->status(),
                'response' => $body,
            ]);

            return [
                'sent' => false,
                'provider' => 'twilio',
                'error' => $errorMessage,
                'code' => $body['code'] ?? null,
                'response' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('Twilio SMS failed: Exception', ['error' => $e->getMessage()]);

            return [
                'sent' => false,
                'provider' => 'twilio',
                'error' => $e->getMessage(),
            ];
        }
    }
}
