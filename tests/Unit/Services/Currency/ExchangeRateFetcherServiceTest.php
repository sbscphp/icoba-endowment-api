<?php

namespace Tests\Unit\Services\Currency;

use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateFetcherService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateFetcherServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_and_store_persists_usd_gbp_and_eur_rates_for_today(): void
    {
        Http::fake([
            'https://open.er-api.com/v6/latest/USD' => Http::response([
                'base_code' => 'USD',
                'rates' => ['NGN' => 1600.0],
            ]),
            'https://open.er-api.com/v6/latest/GBP' => Http::response([
                'base_code' => 'GBP',
                'rates' => ['NGN' => 1823.92],
            ]),
            'https://open.er-api.com/v6/latest/EUR' => Http::response([
                'base_code' => 'EUR',
                'rates' => ['NGN' => 1577.92],
            ]),
        ]);

        Carbon::setTestNow('2026-05-30 10:00:00');

        $service = new ExchangeRateFetcherService;
        $stored = $service->fetchAndStore();

        $this->assertSame(1600.0, $stored['USD']);
        $this->assertSame(1823.92, $stored['GBP']);
        $this->assertSame(1577.92, $stored['EUR']);

        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD',
            'effective_date' => '2026-05-30',
            'source' => 'open.er-api.com',
        ]);
        $this->assertDatabaseMissing('exchange_rates', [
            'currency' => 'GHS',
        ]);

        Carbon::setTestNow();
    }

    public function test_paid_tier_uses_api_key_in_url(): void
    {
        config([
            'endowment.exchange_rate.tier' => 'paid',
            'endowment.exchange_rate.api_key' => 'test-api-key',
            'endowment.exchange_rate.paid_auth' => 'url',
        ]);

        Http::fake([
            'https://v6.exchangerate-api.com/v6/test-api-key/latest/*' => Http::response([
                'base_code' => 'USD',
                'rates' => ['NGN' => 1650.0],
            ]),
        ]);

        Carbon::setTestNow('2026-05-30 10:00:00');

        $service = new ExchangeRateFetcherService;
        $stored = $service->fetchAndStore();

        $this->assertSame(1650.0, $stored['USD']);
        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD',
            'source' => 'exchangerate-api.com',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://v6.exchangerate-api.com/v6/test-api-key/latest/USD';
        });

        Carbon::setTestNow();
    }

    public function test_paid_tier_bearer_auth_omits_key_from_url(): void
    {
        config([
            'endowment.exchange_rate.tier' => 'paid',
            'endowment.exchange_rate.api_key' => 'test-api-key',
            'endowment.exchange_rate.paid_auth' => 'bearer',
        ]);

        Http::fake([
            'https://v6.exchangerate-api.com/v6/latest/*' => Http::response([
                'base_code' => 'USD',
                'rates' => ['NGN' => 1650.0],
            ]),
        ]);

        Carbon::setTestNow('2026-05-30 10:00:00');

        $service = new ExchangeRateFetcherService;
        $stored = $service->fetchAndStore();

        $this->assertSame(1650.0, $stored['USD']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://v6.exchangerate-api.com/v6/latest/USD'
                && $request->hasHeader('Authorization', 'Bearer test-api-key');
        });

        Carbon::setTestNow();
    }

    public function test_should_fetch_respects_cache_until_interval_expires(): void
    {
        config(['endowment.exchange_rate.fetch_interval_hours' => 4]);

        Cache::put('exchange_rate:last_fetch', now()->toIso8601String(), now()->addHours(4));

        $service = new ExchangeRateFetcherService;

        $this->assertFalse($service->shouldFetch());
        $this->assertTrue($service->shouldFetch(force: true));
    }

    public function test_mark_fetched_sets_cache_key(): void
    {
        config(['endowment.exchange_rate.fetch_interval_hours' => 4]);

        $service = new ExchangeRateFetcherService;
        $service->markFetched();

        $this->assertTrue(Cache::has('exchange_rate:last_fetch'));
    }
}
