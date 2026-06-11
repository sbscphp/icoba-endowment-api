<?php

namespace App\Console\Commands;

use App\Services\ThirdParty\SMS\SmsBalanceService;
use Illuminate\Console\Command;

class CheckSmsBalanceCommand extends Command
{
    protected $signature = 'sms:check-balance
                            {--provider= : SMS provider to check (termii, twilio, infobip). Defaults to termii.}
                            {--scheduled : Scheduled run — only email when a threshold is newly crossed.}';

    protected $description = 'Check SMS provider wallet balance and email when budget thresholds are crossed.';

    public function handle(SmsBalanceService $balanceService): int
    {
        $provider = $this->option('provider');
        $result = $balanceService->checkAndNotify(
            is_string($provider) && $provider !== '' ? $provider : null,
            forceNotify: ! $this->option('scheduled'),
        );

        if (! ($result['checked'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'unknown');
            $this->warn("Balance check skipped for {$result['provider']}: {$reason}.");
        
            // Don't fail if the provider just doesn't support it
            return $reason === 'provider_does_not_support_balance_checks'
                ? self::SUCCESS
                : self::FAILURE;
        }

        $balance = $result['balance'] ?? 'unknown';
        $currency = $result['currency'] ?? '';
        $suffix = $currency !== '' ? " {$currency}" : '';
        $this->info("Checked {$result['provider']} balance: {$balance}{$suffix}.");

        if ($result['notified'] ?? false) {
            $this->info('Balance alert email sent.');
        } elseif (isset($result['percentage_used'])) {
            $reason = (string) ($result['reason'] ?? 'unknown');
            $this->line("No alert email sent ({$reason}; budget used {$result['percentage_used']}%).");
        }

        return self::SUCCESS;
    }
}
