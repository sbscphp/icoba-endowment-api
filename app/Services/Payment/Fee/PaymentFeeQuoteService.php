<?php

namespace App\Services\Payment\Fee;

use App\Enums\TransactionApplicationType;

/**
 * Single place that prices a donation. Used by the public quote endpoint, intent creation and
 * the admin preview so every caller sees the same figures. The gateway plays no part: its own
 * cost is subtracted from the fee after payment (transactions.gateway_fee_amount).
 */
final class PaymentFeeQuoteService
{
    public function __construct(
        private readonly PaymentFeeRuleResolver $resolver,
        private readonly PaymentFeeCalculator $calculator,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('platform_charges.enabled', false);
    }

    /**
     * With PLATFORM_CHARGES off, or when no rule covers the amount, the quote is zero and the
     * total equals the donation.
     */
    public function quote(float $donationAmount, string $currency): PaymentFeeQuote
    {
        $currency = strtoupper(trim($currency));
        $donationAmount = round($donationAmount, 2);

        if (! $this->enabled() || $donationAmount <= 0) {
            return PaymentFeeQuote::zero($donationAmount, $currency, $this->enabled());
        }

        $rule = $this->resolver->resolve($currency, $donationAmount);
        if ($rule === null) {
            return PaymentFeeQuote::zero($donationAmount, $currency, true);
        }

        return new PaymentFeeQuote(
            donationAmount: $donationAmount,
            feeAmount: $this->calculator->fee($rule, $donationAmount),
            currency: $currency,
            chargesEnabled: true,
            rule: $rule,
        );
    }

    /**
     * A zero quote regardless of rules (offline bank transfer, admin manual entries, charges off).
     */
    public function zero(float $donationAmount, string $currency): PaymentFeeQuote
    {
        return PaymentFeeQuote::zero($donationAmount, $currency, $this->enabled());
    }

    /**
     * Quote for a transaction type: offline bank transfers and pledge placeholders carry no fee
     * (bank transfers can be opted in through config).
     */
    public function quoteForApplicationType(
        float $donationAmount,
        string $currency,
        ?TransactionApplicationType $applicationType,
    ): PaymentFeeQuote {
        if ($applicationType === TransactionApplicationType::BANK_TRANSFER
            && ! (bool) config('platform_charges.bank_transfer_applies_fee', false)) {
            return $this->zero($donationAmount, $currency);
        }

        if ($applicationType === TransactionApplicationType::PLEDGE_PLACEHOLDER) {
            return $this->zero($donationAmount, $currency);
        }

        return $this->quote($donationAmount, $currency);
    }
}
