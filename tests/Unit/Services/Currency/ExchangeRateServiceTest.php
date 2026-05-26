<?php

namespace Tests\Unit\Services\Currency;

use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ngn_always_returns_one(): void
    {
        $service = new ExchangeRateService;

        $this->assertSame(1.0, $service->rateForCurrencyOnDate('NGN', Carbon::parse('2024-01-15')));
    }

    public function test_exact_date_match_is_preferred(): void
    {
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_to_naira' => 1500.25,
            'effective_date' => Carbon::parse('2024-06-01')->toDateString(),
        ]);
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_to_naira' => 1620.00,
            'effective_date' => Carbon::parse('2024-07-01')->toDateString(),
        ]);

        $service = new ExchangeRateService;

        $this->assertSame(1500.25, $service->rateForCurrencyOnDate('USD', Carbon::parse('2024-06-01')));
        $this->assertSame(1620.00, $service->rateForCurrencyOnDate('USD', Carbon::parse('2024-07-01')));
    }

    public function test_falls_back_to_nearest_prior_rate(): void
    {
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_to_naira' => 1500.25,
            'effective_date' => Carbon::parse('2024-06-01')->toDateString(),
        ]);

        $service = new ExchangeRateService;

        $this->assertSame(1500.25, $service->rateForCurrencyOnDate('USD', Carbon::parse('2024-06-15')));
    }

    public function test_falls_back_to_enum_reference_rate_when_no_row_exists(): void
    {
        $service = new ExchangeRateService;

        $rate = $service->rateForCurrencyOnDate('USD', Carbon::parse('1999-01-01'));

        $this->assertGreaterThan(0, $rate);
    }
}
