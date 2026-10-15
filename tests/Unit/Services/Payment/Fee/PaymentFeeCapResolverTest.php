<?php

namespace Tests\Unit\Services\Payment\Fee;

use App\Models\ExchangeRate;
use App\Models\PaymentFeeRule;
use App\Services\Payment\Fee\PaymentFeeCapResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentFeeCapResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_cap_resolves_to_null(): void
    {
        $this->assertNull(app(PaymentFeeCapResolver::class)->resolve($this->rule('USD', cap: null)));
    }

    public function test_cap_in_the_rule_currency_is_used_as_is(): void
    {
        $cap = app(PaymentFeeCapResolver::class)->resolve($this->rule('NGN', cap: 2000, capCurrency: 'NGN'));

        $this->assertNotNull($cap);
        $this->assertSame(2000.0, $cap->amount);
        $this->assertSame('NGN', $cap->currency);
        $this->assertFalse($cap->converted());
        $this->assertNull($cap->exchangeRate);
    }

    public function test_missing_cap_currency_means_the_rule_currency(): void
    {
        $cap = app(PaymentFeeCapResolver::class)->resolve($this->rule('USD', cap: 5, capCurrency: null));

        $this->assertSame(5.0, $cap?->amount);
        $this->assertSame('USD', $cap?->sourceCurrency);
        $this->assertFalse($cap?->converted());
    }

    public function test_naira_cap_on_a_foreign_rule_uses_the_latest_rate(): void
    {
        $this->rate('USD', 1600, Carbon::today());

        $cap = app(PaymentFeeCapResolver::class)->resolve($this->rule('USD', cap: 2000, capCurrency: 'NGN'));

        $this->assertNotNull($cap);
        $this->assertSame(1.25, $cap->amount);
        $this->assertSame('USD', $cap->currency);
        $this->assertSame(2000.0, $cap->sourceAmount);
        $this->assertSame('NGN', $cap->sourceCurrency);
        $this->assertTrue($cap->converted());
        $this->assertSame(1600.0, $cap->exchangeRate);
        $this->assertSame(Carbon::today()->toDateString(), $cap->rateDate);
    }

    public function test_updated_rate_changes_the_converted_cap(): void
    {
        $rule = $this->rule('USD', cap: 2000, capCurrency: 'NGN');
        $resolver = app(PaymentFeeCapResolver::class);

        $this->rate('USD', 1000, Carbon::today());
        $this->assertSame(2.0, $resolver->resolve($rule)?->amount);

        $this->rate('USD', 2000, Carbon::today());
        $this->assertSame(1.0, $resolver->resolve($rule)?->amount);
    }

    public function test_cap_between_two_foreign_currencies_goes_through_naira(): void
    {
        $this->rate('USD', 1500, Carbon::today());
        $this->rate('GBP', 2000, Carbon::today());

        // 30 USD = 45,000 NGN = 22.50 GBP
        $cap = app(PaymentFeeCapResolver::class)->resolve($this->rule('GBP', cap: 30, capCurrency: 'USD'));

        $this->assertSame(22.5, $cap?->amount);
        $this->assertSame('GBP', $cap?->currency);
    }

    public function test_snapshot_shape(): void
    {
        $this->rate('USD', 1600, Carbon::today());

        $array = app(PaymentFeeCapResolver::class)->resolve($this->rule('USD', cap: 2000, capCurrency: 'NGN'))?->toArray();

        $this->assertSame([
            'amount' => '2000.00',
            'currency' => 'NGN',
            'effective_amount' => '1.25',
            'effective_currency' => 'USD',
            'exchange_rate' => '1600.000000',
            'rate_date' => Carbon::today()->toDateString(),
        ], $array);
    }

    private function rate(string $currency, float $rateToNaira, Carbon $date): void
    {
        ExchangeRate::query()->updateOrCreate(
            ['currency' => $currency, 'effective_date' => $date->toDateString()],
            ['rate_to_naira' => $rateToNaira, 'source' => 'test'],
        );
    }

    private function rule(string $currency, ?float $cap, ?string $capCurrency = null): PaymentFeeRule
    {
        $rule = new PaymentFeeRule;
        $rule->currency = $currency;
        $rule->percentage = 3;
        $rule->fixed_amount = 0;
        $rule->cap_amount = $cap;
        $rule->cap_currency = $capCurrency;

        return $rule;
    }
}
