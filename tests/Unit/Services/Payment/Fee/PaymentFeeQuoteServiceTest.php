<?php

namespace Tests\Unit\Services\Payment\Fee;

use App\Enums\TransactionApplicationType;
use App\Models\ExchangeRate;
use App\Models\PaymentFeeRule;
use App\Services\Payment\Fee\PaymentFeeQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentFeeQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform_charges.enabled' => true]);
    }

    public function test_quote_is_zero_when_platform_charges_are_disabled(): void
    {
        config(['platform_charges.enabled' => false]);
        $this->rule('NGN', 3.0);

        $quote = app(PaymentFeeQuoteService::class)->quote(10000, 'NGN');

        $this->assertFalse($quote->chargesEnabled);
        $this->assertFalse($quote->feeApplied());
        $this->assertSame(0.0, $quote->feeAmount());
        $this->assertSame(10000.0, $quote->totalAmount());
        $this->assertNull($quote->rule);
    }

    public function test_quote_is_zero_when_no_rule_covers_the_currency(): void
    {
        $this->rule('USD', 3.0);

        $quote = app(PaymentFeeQuoteService::class)->quote(10000, 'NGN');

        $this->assertTrue($quote->chargesEnabled);
        $this->assertFalse($quote->feeApplied());
        $this->assertSame(10000.0, $quote->totalAmount());
    }

    public function test_quote_applies_the_currency_rule_to_the_donation(): void
    {
        $this->rule('NGN', 3.0);

        $quote = app(PaymentFeeQuoteService::class)->quote(10000, 'ngn');

        $this->assertSame(10000.0, $quote->donationAmount);
        $this->assertSame(300.0, $quote->feeAmount());
        $this->assertSame(10300.0, $quote->totalAmount());
        $this->assertSame('NGN', $quote->currency);
        $this->assertSame('Service fee NGN', $quote->rule?->name);
    }

    public function test_band_selects_by_donation_amount(): void
    {
        $this->rule('NGN', 3.0, max: 2499.99, name: 'small');
        $this->rule('NGN', 2.0, fixed: 100, min: 2500, name: 'large');

        $small = app(PaymentFeeQuoteService::class)->quote(2000, 'NGN');
        $large = app(PaymentFeeQuoteService::class)->quote(10000, 'NGN');

        $this->assertSame('small', $small->rule?->name);
        $this->assertSame(60.0, $small->feeAmount());
        $this->assertSame('large', $large->rule?->name);
        $this->assertSame(300.0, $large->feeAmount());
    }

    public function test_naira_cap_on_a_usd_rule_is_converted_at_the_latest_rate(): void
    {
        ExchangeRate::query()->updateOrCreate(
            ['currency' => 'USD', 'effective_date' => now()->toDateString()],
            ['rate_to_naira' => 1600, 'source' => 'test'],
        );
        $this->rule('USD', 3.0, cap: 2000, capCurrency: 'NGN');

        $service = app(PaymentFeeQuoteService::class);

        // 3% of 500 USD = 15 USD, capped at 2000 NGN = 1.25 USD
        $capped = $service->quote(500, 'USD');
        $this->assertSame(1.25, $capped->feeAmount());
        $this->assertSame(501.25, $capped->totalAmount());

        // 3% of 10 USD = 0.30 USD, under the cap
        $this->assertSame(0.3, $service->quote(10, 'USD')->feeAmount());

        $array = $capped->toArray();
        $this->assertSame('2000.00', $array['rule']['cap_amount']);
        $this->assertSame('NGN', $array['rule']['cap_currency']);
        $this->assertSame('1.25', $array['rule']['effective_cap_amount']);
        $this->assertSame('1600.000000', $array['rule']['cap_exchange_rate']);

        $snapshot = $capped->toTransactionAttributes()['fee_snapshot'];
        $this->assertSame('NGN', $snapshot['rule']['cap_currency']);
        $this->assertSame('1.25', $snapshot['cap']['effective_amount']);
        $this->assertSame('1600.000000', $snapshot['cap']['exchange_rate']);
        $this->assertSame(now()->toDateString(), $snapshot['cap']['rate_date']);
    }

    public function test_cap_in_the_rule_currency_needs_no_rate(): void
    {
        $this->rule('NGN', 3.0, cap: 2000);

        $quote = app(PaymentFeeQuoteService::class)->quote(500000, 'NGN');

        $this->assertSame(2000.0, $quote->feeAmount());
        $this->assertSame('NGN', $quote->toArray()['rule']['cap_currency']);
        $this->assertNull($quote->toArray()['rule']['cap_exchange_rate']);
        $this->assertNull($quote->toTransactionAttributes()['fee_snapshot']['cap']['exchange_rate']);
    }

    public function test_inactive_rules_are_ignored(): void
    {
        $this->rule('NGN', 3.0, active: false);

        $this->assertFalse(app(PaymentFeeQuoteService::class)->quote(10000, 'NGN')->feeApplied());
    }

    public function test_bank_transfer_carries_no_fee_unless_configured(): void
    {
        $this->rule('NGN', 3.0);
        $service = app(PaymentFeeQuoteService::class);

        $offline = $service->quoteForApplicationType(10000, 'NGN', TransactionApplicationType::BANK_TRANSFER);
        $this->assertFalse($offline->feeApplied());

        config(['platform_charges.bank_transfer_applies_fee' => true]);
        $charged = $service->quoteForApplicationType(10000, 'NGN', TransactionApplicationType::BANK_TRANSFER);
        $this->assertSame(300.0, $charged->feeAmount());
    }

    public function test_transaction_attributes_carry_fee_total_and_snapshot(): void
    {
        $rule = $this->rule('NGN', 3.0);

        $attributes = app(PaymentFeeQuoteService::class)->quote(10000, 'NGN')->toTransactionAttributes();

        $this->assertSame(300.0, $attributes['fee_amount']);
        $this->assertSame(10300.0, $attributes['total_amount']);
        $this->assertSame($rule->uuid, $attributes['fee_rule_uuid']);
        $this->assertSame('3.0000', $attributes['fee_snapshot']['rule']['percentage']);
    }

    private function rule(
        string $currency,
        float $percentage,
        float $fixed = 0,
        ?float $cap = null,
        float $min = 0.01,
        ?float $max = null,
        bool $active = true,
        ?string $name = null,
        ?string $capCurrency = null,
    ): PaymentFeeRule {
        return PaymentFeeRule::query()->create([
            'name' => $name ?? 'Service fee '.$currency,
            'currency' => $currency,
            'min_amount' => $min,
            'max_amount' => $max,
            'percentage' => $percentage,
            'fixed_amount' => $fixed,
            'cap_amount' => $cap,
            'cap_currency' => $capCurrency,
            'is_active' => $active,
        ]);
    }
}
