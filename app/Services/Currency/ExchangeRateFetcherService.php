<?php

namespace App\Services\Currency;

use App\Enums\Currency;
use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ExchangeRateFetcherService
{
    private const FREE_BASE_URL = 'https://open.er-api.com/v6/latest';

    private const PAID_BASE_URL = 'https://v6.exchangerate-api.com/v6';

    public function fetchIntervalHours(): int
    {
        return max(1, (int) config('endowment.exchange_rate.fetch_interval_hours', 4));
    }

    public function shouldFetch(bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        return ! Cache::has($this->cacheKey());
    }

    public function markFetched(): void
    {
        Cache::put(
            $this->cacheKey(),
            now()->toIso8601String(),
            now()->addHours($this->fetchIntervalHours())
        );
    }

    /**
     * @return array<string, float> currency => rate_to_naira
     */
    public function fetchAndStore(?CarbonInterface $effectiveDate = null): array
    {
        $effectiveDate ??= now();
        $dateString = $effectiveDate->toDateString();
        $stored = [];

        foreach (Currency::fxFetchable() as $currency) {
            $rateToNaira = $this->fetchRateToNaira($currency);

            ExchangeRate::query()->updateOrCreate(
                [
                    'currency' => $currency->value,
                    'effective_date' => $dateString,
                ],
                [
                    'rate_to_naira' => round($rateToNaira, 6),
                    'source' => $this->sourceLabel(),
                ]
            );

            $stored[$currency->value] = round($rateToNaira, 6);
        }

        return $stored;
    }

    private function fetchRateToNaira(Currency $currency): float
    {
        $response = $this->httpClient()->get($this->apiUrlFor($currency));

        if (! $response->successful()) {
            throw new RuntimeException(
                'Exchange rate API request failed for '.$currency->value.' with status '.$response->status()
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Exchange rate API returned an invalid response for '.$currency->value.'.');
        }

        $result = $payload['result'] ?? null;
        if (is_string($result) && $result !== 'success') {
            $errorType = (string) ($payload['error-type'] ?? $payload['error_type'] ?? 'unknown');

            throw new RuntimeException(
                'Exchange rate API returned error for '.$currency->value.': '.$errorType
            );
        }

        $rates = $payload['rates'] ?? $payload['conversion_rates'] ?? null;
        if (! is_array($rates)) {
            throw new RuntimeException('Exchange rate API response is missing rates for '.$currency->value.'.');
        }

        $ngnRate = (float) ($rates['NGN'] ?? 0);

        if ($ngnRate <= 0) {
            throw new RuntimeException('Exchange rate API response is missing NGN rate for '.$currency->value.'.');
        }

        return $ngnRate;
    }

    private function httpClient(): PendingRequest
    {
        $client = Http::timeout(15);

        if ($this->tier() === 'paid' && $this->paidAuth() === 'bearer') {
            $client = $client->withToken($this->apiKey());
        }

        return $client;
    }

    private function apiUrlFor(Currency $currency): string
    {
        if ($this->tier() === 'paid') {
            if ($this->paidAuth() === 'bearer') {
                return self::PAID_BASE_URL.'/latest/'.$currency->value;
            }

            return self::PAID_BASE_URL.'/'.$this->apiKey().'/latest/'.$currency->value;
        }

        return self::FREE_BASE_URL.'/'.$currency->value;
    }

    private function tier(): string
    {
        $tier = strtolower(trim((string) config('endowment.exchange_rate.tier', 'free')));

        return $tier === 'paid' ? 'paid' : 'free';
    }

    private function paidAuth(): string
    {
        $auth = strtolower(trim((string) config('endowment.exchange_rate.paid_auth', 'url')));

        return $auth === 'bearer' ? 'bearer' : 'url';
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('endowment.exchange_rate.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('EXCHANGE_RATE_API_KEY is required when EXCHANGE_RATE_API_TIER is paid.');
        }

        return $apiKey;
    }

    private function sourceLabel(): string
    {
        return $this->tier() === 'paid' ? 'exchangerate-api.com' : 'open.er-api.com';
    }

    private function cacheKey(): string
    {
        return (string) config('endowment.exchange_rate.cache_key', 'exchange_rate:last_fetch');
    }
}
