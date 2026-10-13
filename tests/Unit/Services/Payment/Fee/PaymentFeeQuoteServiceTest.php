<?php

namespace Tests\Unit\Services\Payment\Fee;

use App\Enums\TransactionApplicationType;
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
    ): PaymentFeeRule {
        return PaymentFeeRule::query()->create([
            'name' => $name ?? 'Service fee '.$currency,
            'currency' => $currency,
            'min_amount' => $min,
            'max_amount' => $max,
            'percentage' => $percentage,
            'fixed_amount' => $fixed,
            'cap_amount' => $cap,
            'is_active' => $active,
        ]);
    }
}
