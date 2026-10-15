<?php

namespace Tests\Unit\Services\Payment\Fee;

use App\Models\PaymentFeeRule;
use App\Services\Payment\Fee\PaymentFeeCalculator;
use App\Services\Payment\Fee\ResolvedFeeCap;
use PHPUnit\Framework\TestCase;

final class PaymentFeeCalculatorTest extends TestCase
{
    public function test_fee_is_percentage_of_donation(): void
    {
        $this->assertSame(300.0, (new PaymentFeeCalculator)->fee($this->rule(percentage: 3.0), 10000));
    }

    public function test_fee_adds_fixed_part(): void
    {
        $this->assertSame(250.0, (new PaymentFeeCalculator)->fee($this->rule(percentage: 1.5, fixed: 100), 10000));
    }

    public function test_fee_respects_cap(): void
    {
        $this->assertSame(2000.0, (new PaymentFeeCalculator)->fee($this->rule(percentage: 3.0, cap: 2000), 500000));
    }

    public function test_resolved_cap_in_rule_currency_overrides_the_raw_cap(): void
    {
        // Rule says "cap 2000 NGN" but prices USD; the resolver hands over the converted 1.25 USD.
        $rule = $this->rule(percentage: 3.0, cap: 2000);
        $cap = new ResolvedFeeCap(
            amount: 1.25,
            currency: 'USD',
            sourceAmount: 2000,
            sourceCurrency: 'NGN',
            exchangeRate: 1600,
            rateDate: '2026-10-15',
        );

        $this->assertSame(1.25, (new PaymentFeeCalculator)->fee($rule, 500, $cap));
        $this->assertSame(0.3, (new PaymentFeeCalculator)->fee($rule, 10, $cap));
    }

    public function test_fee_rounds_half_up_to_two_decimals(): void
    {
        // 33.33 * 2% = 0.6666 -> 0.67
        $this->assertSame(0.67, (new PaymentFeeCalculator)->fee($this->rule(percentage: 2.0), 33.33));
    }

    public function test_fee_is_zero_for_non_positive_donation(): void
    {
        $rule = $this->rule(percentage: 3.0, fixed: 50);

        $this->assertSame(0.0, (new PaymentFeeCalculator)->fee($rule, 0));
        $this->assertSame(0.0, (new PaymentFeeCalculator)->fee($rule, -10));
    }

    private function rule(float $percentage = 0, float $fixed = 0, ?float $cap = null): PaymentFeeRule
    {
        $rule = new PaymentFeeRule;
        $rule->percentage = $percentage;
        $rule->fixed_amount = $fixed;
        $rule->cap_amount = $cap;

        return $rule;
    }
}
