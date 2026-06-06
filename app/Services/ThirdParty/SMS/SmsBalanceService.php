<?php

namespace App\Services\ThirdParty\SMS;

use Illuminate\Support\Facades\Log;

class SmsBalanceService
{
    public function __construct(
        private readonly InfobipService $infobipService,
        private readonly TermiiService $termiiService,
        private readonly TwilioService $twilioService,
        private readonly SmsBalanceAlertService $balanceAlertService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checkAndNotify(?string $provider = null, bool $forceNotify = false): array
    {
        $provider = strtolower(trim((string) ($provider ?? config('services.sms.driver', 'log'))));

        if (! in_array($provider, ['termii', 'twilio', 'infobip'], true)) {
            return [
                'checked' => false,
                'provider' => $provider,
                'reason' => 'provider_does_not_support_balance_checks',
            ];
        }

        $result = match ($provider) {
            'termii' => $this->termiiService->fetchBalance(),
            'twilio' => $this->twilioService->fetchBalance(),
            'infobip' => $this->infobipService->fetchBalance(),
        };

        if ($result === null) {
            Log::warning('SMS balance check returned no data', ['provider' => $provider]);

            return [
                'checked' => false,
                'provider' => $provider,
                'reason' => 'balance_fetch_failed',
            ];
        }

        $notification = ['notified' => false, 'percentage_used' => null, 'threshold_percent' => null, 'reason' => null];

        if (isset($result['balance'])) {
            $notification = $this->balanceAlertService->notifyIfBudgetThresholdCrossed(
                $provider,
                (float) $result['balance'],
                $result,
                $forceNotify
            );
        }

        return array_merge($result, $notification, [
            'checked' => true,
            'provider' => $provider,
        ]);
    }
}
