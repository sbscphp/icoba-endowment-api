<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\StripeBalanceFee;
use PHPUnit\Framework\TestCase;

final class StripeBalanceFeeTest extends TestCase
{
    public function test_same_currency_fee_is_returned_in_major_units(): void
    {
        $result = StripeBalanceFee::inCurrency(['fee' => 320, 'currency' => 'usd'], 'USD');

        $this->assertSame(3.2, $result['fee']);
        $this->assertSame('USD', $result['settlement_currency']);
        $this->assertFalse($result['converted']);
    }

    public function test_settlement_in_another_currency_is_converted_back_with_stripes_rate(): void
    {
        // NGN charge settled in USD: 10,300 NGN -> 6.44 USD, rate 0.000625; Stripe fee 0.49 USD.
        $result = StripeBalanceFee::inCurrency(['fee' => 49, 'currency' => 'usd', 'exchange_rate' => 0.000625], 'NGN');

        $this->assertSame(784.0, $result['fee']);
        $this->assertSame(0.49, $result['settlement_fee']);
        $this->assertSame('USD', $result['settlement_currency']);
        $this->assertTrue($result['converted']);
    }

    public function test_other_currency_without_rate_keeps_settlement_fee_but_no_converted_fee(): void
    {
        $result = StripeBalanceFee::inCurrency(['fee' => 49, 'currency' => 'usd'], 'NGN');

        $this->assertNull($result['fee']);
        $this->assertSame(0.49, $result['settlement_fee']);
    }

    public function test_missing_balance_yields_nothing(): void
    {
        $this->assertNull(StripeBalanceFee::inCurrency(null, 'NGN')['fee']);
        $this->assertNull(StripeBalanceFee::inCurrency(['currency' => 'usd'], 'NGN')['fee']);
    }
}
