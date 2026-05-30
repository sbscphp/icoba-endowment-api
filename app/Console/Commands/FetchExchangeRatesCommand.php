<?php

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Mail\ExchangeRateStaleNotification;
use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateFetcherService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'exchange:fetch-rates {--force : Bypass the fetch interval cache and call the API}';

    protected $description = 'Fetch USD, GBP, and EUR to NGN rates from ExchangeRate-API and store them for today.';

    public function handle(ExchangeRateFetcherService $fetcher): int
    {
        $this->checkForStaleRates();

        if (! $fetcher->shouldFetch((bool) $this->option('force'))) {
            $this->info('Exchange rates were fetched recently. Skipping API call (use --force to override).');

            return self::SUCCESS;
        }

        $this->info('Fetching exchange rates from ExchangeRate-API...');

        try {
            $stored = $fetcher->fetchAndStore();
        } catch (\Throwable $exception) {
            $this->error('Failed to fetch exchange rates: '.$exception->getMessage());

            return self::FAILURE;
        }

        $fetcher->markFetched();

        foreach ($stored as $currency => $rate) {
            $this->info(sprintf('%s → NGN: %s', $currency, $rate));
        }

        $this->info('Exchange rates saved for '.now()->toDateString().'.');

        return self::SUCCESS;
    }

    private function checkForStaleRates(): void
    {
        $staleDays = max(1, (int) config('endowment.exchange_rate.stale_alert_days', 2));
        $fxCurrencies = array_map(
            fn (Currency $currency): string => $currency->value,
            Currency::fxFetchable()
        );

        $latestUpdatedAt = ExchangeRate::query()
            ->whereIn('currency', $fxCurrencies)
            ->max('updated_at');

        if ($latestUpdatedAt === null) {
            $message = 'CRITICAL: No exchange rate records found in the database. Run exchange:fetch-rates to seed live rates.';
            $this->error($message);
            $this->sendStaleAlert($message);

            return;
        }

        $lastUpdate = Carbon::parse($latestUpdatedAt);

        if ($lastUpdate->lessThan(now()->subDays($staleDays)->startOfDay())) {
            $message = sprintf(
                'WARNING: Exchange rate data has not been updated for more than %d days. Last update: %s',
                $staleDays,
                $lastUpdate->toDateTimeString()
            );
            $this->warn($message);
            $this->sendStaleAlert($message);

            return;
        }

        $this->info('Exchange rate data appears fresh. Last update: '.$lastUpdate->toDateTimeString());
    }

    private function sendStaleAlert(string $message): void
    {
        $to = (string) config('endowment.exchange_rate.alert_to');
        $cc = (string) config('endowment.exchange_rate.alert_cc');

        if ($to === '') {
            return;
        }

        $mail = Mail::to($to);

        if ($cc !== '') {
            $mail->cc($cc);
        }

        $mail->send(new ExchangeRateStaleNotification($message));
    }
}
